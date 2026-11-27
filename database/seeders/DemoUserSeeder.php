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
 * Demo officers: 4 per level (State, District, Block, Cluster) plus one pending and one rejected account (Modification V13),
 * and since 27-11-2026 a District Programmer (District) and a Block MIS (Block).
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
        $desig = fn ($code) => MasterValue::idFor('designation', $code);

        // Modification V13: demo officers sit on the current Location Master (the first two districts
        // by code, the first two blocks of each and the first cluster of each block), never fixed IDs.
        $districts = District::where('is_active', true)->whereHas('blocks.clusters')->orderBy('code')->take(2)->get();
        if ($districts->count() < 2) {
            $this->command?->warn('DemoUserSeeder needs at least two districts with blocks and clusters.');

            return;
        }
        $blocks = $districts->flatMap(fn ($d) => Block::where('district_id', $d->id)->where('is_active', true)->whereHas('clusters')->orderBy('code')->take(2)->get())->values();
        $clusters = $blocks->map(fn ($b) => Cluster::where('block_id', $b->id)->where('is_active', true)->orderBy('code')->first())->values();
        [$d1, $d2] = [$districts[0], $districts[1]];
        $b = fn (int $i) => $blocks[$i % $blocks->count()];
        $c = fn (int $i) => $clusters[$i % $clusters->count()];
        // V16 (27-11-2026): approving and managing users follow the designation (9000000015 District Programmer, 9000000025 Block MIS), no grants

        $users = [
            // mobile, name, gender, level, designation, location, status, extra permissions
            ['9000000001', 'Ananya Sharma',    'female', UserLevel::State,    'joint_director',               null,  UserStatus::Active, []],
            ['9000000002', 'Bikash Kalita',    'male',   UserLevel::State,    'joint_director',               null,  UserStatus::Active, []],
            ['9000000003', 'Chandana Bora',    'female', UserLevel::State,    'joint_director',               null,  UserStatus::Active, []],
            ['9000000004', 'Dipankar Saikia',  'male',   UserLevel::State,    'joint_director',               null,  UserStatus::Active, []],
            ['9000000011', 'Rakesh Baruah',    'male',   UserLevel::District, 'district_education_officer',   $d1,   UserStatus::Active, []],
            ['9000000012', 'Meera Kalita',     'female', UserLevel::District, 'inspector_of_schools',         $d1,   UserStatus::Active, []],
            ['9000000013', 'Nabajit Deka',     'male',   UserLevel::District, 'district_education_officer',   $d2,   UserStatus::Active, []],
            ['9000000014', 'Rupali Das',       'female', UserLevel::District, 'district_project_coordinator', $d2,   UserStatus::Active, []],
            ['9000000021', 'Sanjay Das',       'male',   UserLevel::Block,    'block_education_officer',      $b(0), UserStatus::Active, []],
            ['9000000022', 'Priya Gogoi',      'female', UserLevel::Block,    'block_education_officer',      $b(1), UserStatus::Active, []],
            ['9000000023', 'Imran Hussain',    'male',   UserLevel::Block,    'block_mission_coordinator',    $b(2), UserStatus::Active, []],
            ['9000000024', 'Deepa Bora',       'female', UserLevel::Block,    'block_resource_person',        $b(3), UserStatus::Active, []],
            ['9000000031', 'Arjun Saikia',     'male',   UserLevel::Cluster,  'cluster_resource_coordinator', $c(0), UserStatus::Active, []],
            ['9000000032', 'Kavita Deka',      'female', UserLevel::Cluster,  'cluster_resource_coordinator', $c(1), UserStatus::Active, []],
            ['9000000033', 'Rohit Nath',       'male',   UserLevel::Cluster,  'cluster_resource_coordinator', $c(2), UserStatus::Active, []],
            ['9000000034', 'Sunita Rabha',     'female', UserLevel::Cluster,  'cluster_resource_coordinator', $c(3), UserStatus::Active, []],
            // Approval queue
            ['9000000041', 'Amit Phukan',      'male',   UserLevel::Cluster,  'cluster_resource_coordinator', $c(0), UserStatus::Pending, []],
            ['9000000044', 'Gita Barman',      'female', UserLevel::Cluster,  'cluster_resource_coordinator', $c(1), UserStatus::Rejected, []],
            // 27-11-2026: District Programmer (District level) and Block MIS (Block level); last, so the DEMO00nn employee IDs of the others stay
            ['9000000015', 'Pranjal Bhuyan',   'male',   UserLevel::District, 'district_programmer',          $d1,   UserStatus::Active, []],
            ['9000000025', 'Nisha Talukdar',   'female', UserLevel::Block,    'block_mis',                    $b(0), UserStatus::Active, []],
        ];
        $idProof = $this->idProofFile();
        $n = 0;

        foreach ($users as [$mobile, $name, $gender, $level, $designation, $loc, $status, $perms]) {
            $n++;
            $user = User::updateOrCreate(['mobile' => $mobile], [
                'name' => $name,
                'gender' => $gender,
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
            $district = $block?->loadMissing('district')->district ?? ($loc instanceof District ? $loc : null);
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
