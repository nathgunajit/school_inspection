<?php

namespace Database\Seeders;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\MasterValue;
use App\Models\Permission;
use App\Models\RegistrationRequest;
use App\Models\State;
use App\Models\User;
use App\Models\UserJurisdiction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Demo officers at every level, plus pending / rejected / inactive accounts.
 * All demo accounts use the password Demo@1234. Never run in production.
 */
class DemoUserSeeder extends Seeder
{
    public const PASSWORD = 'Demo@1234';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DemoUserSeeder skipped in production.');

            return;
        }

        $state = State::where('code', '18')->firstOrFail();
        $d = fn ($code) => District::where('code', $code)->firstOrFail();
        $b = fn ($code) => Block::where('code', $code)->firstOrFail();
        $c = fn ($code) => Cluster::where('code', $code)->firstOrFail();
        $desig = fn ($code) => MasterValue::idFor('designation', $code);

        $users = [
            // mobile, name, level, designation, location, status, extra permissions
            ['9000000001', 'Ananya Sharma',   UserLevel::State,    'joint_director',               null,                    UserStatus::Active, [Permission::APPROVE_REGISTRATIONS]],
            ['9000000011', 'Rakesh Baruah',   UserLevel::District, 'district_education_officer',   $d('1801'),              UserStatus::Active, [Permission::APPROVE_REGISTRATIONS, Permission::MANAGE_USERS]],
            ['9000000012', 'Meera Kalita',    UserLevel::District, 'inspector_of_schools',         $d('1802'),              UserStatus::Active, []],
            ['9000000021', 'Sanjay Das',      UserLevel::Block,    'block_education_officer',      $b('180101'),            UserStatus::Active, [Permission::APPROVE_REGISTRATIONS]],
            ['9000000022', 'Priya Gogoi',     UserLevel::Block,    'block_education_officer',      $b('180102'),            UserStatus::Active, []],
            ['9000000023', 'Imran Hussain',   UserLevel::Block,    'block_mission_coordinator',    $b('180201'),            UserStatus::Active, []],
            ['9000000024', 'Deepa Bora',      UserLevel::Block,    'block_resource_person',        $b('180202'),            UserStatus::Active, []],
            ['9000000031', 'Arjun Saikia',    UserLevel::Cluster,  'cluster_resource_coordinator', $c('18010101'),          UserStatus::Active, []],
            ['9000000032', 'Kavita Deka',     UserLevel::Cluster,  'cluster_resource_coordinator', $c('18010102'),          UserStatus::Active, []],
            ['9000000033', 'Rohit Nath',      UserLevel::Cluster,  'cluster_resource_coordinator', $c('18010201'),          UserStatus::Active, []],
            ['9000000034', 'Sunita Rabha',    UserLevel::Cluster,  'cluster_resource_coordinator', $c('18010202'),          UserStatus::Active, []],
            ['9000000035', 'Vikram Choudhury', UserLevel::Cluster, 'cluster_resource_coordinator', $c('18020101'),          UserStatus::Active, []],
            ['9000000036', 'Nisha Hazarika',  UserLevel::Cluster,  'cluster_resource_coordinator', $c('18020102'),          UserStatus::Active, []],
            ['9000000037', 'Manoj Talukdar',  UserLevel::Cluster,  'cluster_resource_coordinator', $c('18020201'),          UserStatus::Active, []],
            ['9000000038', 'Pooja Medhi',     UserLevel::Cluster,  'cluster_resource_coordinator', $c('18020202'),          UserStatus::Active, []],
            // Approval queue and other statuses
            ['9000000041', 'Amit Phukan',     UserLevel::Cluster,  'cluster_resource_coordinator', $c('18010101'),          UserStatus::Pending, []],
            ['9000000042', 'Rina Bhuyan',     UserLevel::Block,    'block_resource_person',        $b('180102'),            UserStatus::Pending, []],
            ['9000000043', 'Suresh Kakoti',   UserLevel::District, 'district_project_coordinator', $d('1802'),              UserStatus::Pending, []],
            ['9000000044', 'Gita Barman',     UserLevel::Cluster,  'cluster_resource_coordinator', $c('18020101'),          UserStatus::Rejected, []],
            ['9000000045', 'Hemant Dutta',    UserLevel::Cluster,  'cluster_resource_coordinator', $c('18010202'),          UserStatus::Inactive, []],
        ];

        $idProof = $this->idProofFile();
        $n = 0;

        foreach ($users as [$mobile, $name, $level, $designation, $loc, $status, $perms]) {
            $n++;
            $user = User::updateOrCreate(['mobile' => $mobile], [
                'name' => $name,
                'designation_id' => $desig($designation),
                'employee_id' => 'DEMO'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'email' => null,
                'password' => self::PASSWORD,
                'level' => $level,
                'status' => $status,
                'id_proof_path' => $idProof,
                'id_proof_original_name' => 'demo-id-card.png',
                'must_change_password' => false,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ]);

            // Jurisdiction from the location (cluster -> block -> district -> state)
            $cluster = $loc instanceof Cluster ? $loc : null;
            $block = $cluster?->block ?? ($loc instanceof Block ? $loc : null);
            $district = $block?->district ?? ($loc instanceof District ? $loc : null);
            UserJurisdiction::updateOrCreate(
                ['user_id' => $user->id, 'end_date' => null],
                [
                    'state_id' => $state->id,
                    'district_id' => $level->needsDistrict() ? $district?->id : null,
                    'block_id' => $level->needsBlock() ? $block?->id : null,
                    'cluster_id' => $level->needsCluster() ? $cluster?->id : null,
                    'start_date' => now()->subMonths(6)->toDateString(),
                ]
            );

            $user->directPermissions()->sync(
                Permission::whereIn('slug', $perms)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['granted_at' => now()]])->all()
            );

            $reqStatus = match ($status) {
                UserStatus::Pending => 'pending',
                UserStatus::Rejected => 'rejected',
                default => 'approved',
            };
            RegistrationRequest::updateOrCreate(['user_id' => $user->id], [
                'status' => $reqStatus,
                'reason' => $reqStatus === 'rejected' ? 'Identity proof is not readable. Please upload a clear copy.' : null,
                'decided_at' => $reqStatus === 'pending' ? null : now()->subMonths(5),
            ]);
        }

        $this->command?->info('Demo users seeded: '.count($users).' accounts, password '.self::PASSWORD);
    }

    /** A simple placeholder ID card image on the private disk. */
    private function idProofFile(): string
    {
        $path = 'id-proofs/demo-id-card.png';
        $disk = Storage::disk('private');
        if ($disk->exists($path)) {
            return $path;
        }

        $img = imagecreatetruecolor(640, 400);
        imagefill($img, 0, 0, imagecolorallocate($img, 238, 242, 255));
        imagefilledrectangle($img, 0, 0, 640, 70, imagecolorallocate($img, 67, 56, 202));
        $white = imagecolorallocate($img, 255, 255, 255);
        $ink = imagecolorallocate($img, 30, 41, 59);
        imagestring($img, 5, 24, 26, 'DEPARTMENT OF SCHOOL EDUCATION - DEMO ID CARD', $white);
        imagefilledrectangle($img, 24, 100, 184, 300, imagecolorallocate($img, 199, 210, 254));
        imagestring($img, 5, 210, 120, 'Name: Demo Officer', $ink);
        imagestring($img, 5, 210, 160, 'Employee ID: DEMO', $ink);
        imagestring($img, 5, 210, 200, 'For testing only', $ink);

        ob_start();
        imagepng($img);
        $disk->put($path, ob_get_clean());
        imagedestroy($img);

        return $path;
    }
}
