<?php

namespace Database\Seeders;

use App\Models\Cluster;
use App\Models\MasterValue;
use App\Models\State;
use App\Questionnaire\QuestionnaireDefinition as Q;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Load-test data (Modification V5 Step 2, see LOAD_TEST.md). Local/testing only.
 *
 *  - LOADTEST_USERS officers (Cluster level, Active): mobiles 7000000001…, password Load@1234.
 *    Officer n owns school n (its "form school") so the start rules never block a virtual user.
 *  - LOADTEST_SCHOOLS schools (UDISE 19000000001…) spread over the existing clusters.
 *  - LOADTEST_INSPECTIONS submitted inspections before the current month, each with every V2 answer,
 *    so lists, reports and indexes work on a realistic volume.
 *
 * Run on a backed-up database and restore it afterwards (LOAD_TEST.md, section 2).
 */
class LoadTestSeeder extends Seeder
{
    public const PASSWORD = 'Load@1234';
    public const MOBILE_BASE = 7000000000;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->error('LoadTestSeeder runs only when APP_ENV is local or testing.');

            return;
        }
        if (DB::table('users')->where('mobile', (string) (self::MOBILE_BASE + 1))->exists()) {
            $this->command?->warn('Load-test data already present (mobile 7000000001). Restore the backup first.');

            return;
        }

        $nUsers = (int) env('LOADTEST_USERS', 500);
        $nSchools = max($nUsers, (int) env('LOADTEST_SCHOOLS', 5000));
        $nInsp = (int) env('LOADTEST_INSPECTIONS', 10000);

        $state = State::where('code', '18')->firstOrFail();
        $clusters = Cluster::with('block.district')->orderBy('id')->get()->values();
        abort_if($clusters->isEmpty(), 500, 'Seed the demo locations first.');
        $k = $clusters->count();
        $now = now()->toDateTimeString();
        $desig = MasterValue::idFor('designation', 'cluster_resource_coordinator');
        $hash = Hash::make(self::PASSWORD);

        // ---- Schools: school i lies in cluster (i-1) % k
        $schoolIds = [];
        foreach (array_chunk(range(1, $nSchools), 1000) as $chunk) {
            DB::table('schools')->insert(array_map(function ($i) use ($clusters, $k, $state, $now) {
                $c = $clusters[($i - 1) % $k];

                return [
                    'udise_code' => (string) (19000000000 + $i), 'name' => 'Load Test School '.$i,
                    'state_id' => $state->id, 'district_id' => $c->block->district_id, 'block_id' => $c->block_id, 'cluster_id' => $c->id,
                    'latitude' => 26.1 + ($i % 100) / 1000, 'longitude' => 91.7 + ($i % 100) / 1000,
                    'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ];
            }, $chunk));
        }
        $schoolIds = DB::table('schools')->where('udise_code', 'like', '19%')->orderBy('udise_code')->pluck('id')->all();

        // ---- Officers: officer n lies in cluster (n-1) % k, the same cluster as school n
        foreach (array_chunk(range(1, $nUsers), 1000) as $chunk) {
            DB::table('users')->insert(array_map(fn ($n) => [
                'name' => 'Load Officer '.$n, 'designation_id' => $desig, 'employee_id' => 'LT-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
                'mobile' => (string) (self::MOBILE_BASE + $n), 'password' => $hash, 'level' => 'cluster', 'status' => 'active',
                'must_change_password' => 0, 'failed_login_attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
        $userIds = DB::table('users')->where('employee_id', 'like', 'LT-%')->orderBy('employee_id')->pluck('id')->all();
        foreach (array_chunk($userIds, 1000, true) as $chunk) {
            $rows = [];
            foreach ($chunk as $idx => $uid) {
                $c = $clusters[$idx % $k];
                $rows[] = ['user_id' => $uid, 'state_id' => $state->id, 'district_id' => $c->block->district_id, 'block_id' => $c->block_id,
                    'cluster_id' => $c->id, 'start_date' => now()->subYear()->toDateString(), 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('user_jurisdictions')->insert($rows);
        }

        // ---- Historic submitted inspections (before this month) with all V2 answers
        $codes = Q::all();
        $monthStart = now()->startOfMonth();
        $made = 0;
        for ($i = 0; $made < $nInsp; $i++) {
            $batch = [];
            for ($j = 0; $j < 500 && $made + count($batch) < $nInsp; $j++) {
                $seq = $made + count($batch) + 1;
                $s = $seq % count($schoolIds);            // school index
                $c = $clusters[$s % $k];
                // an officer from the same cluster, a date in the 12 months before this month
                $officerIdx = (($s % $k) + $k * ($seq % max(1, intdiv(count($userIds), $k)))) % count($userIds);
                $date = $monthStart->copy()->subDays(1 + ($seq * 7) % 360);
                $batch[] = [
                    'inspection_no' => 'LT/'.$date->year.'/'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
                    'officer_id' => $userIds[$officerIdx], 'school_id' => $schoolIds[$s], 'inspection_date' => $date->toDateString(),
                    'started_at' => $date->copy()->setTime(10, 0), 'submitted_at' => $date->copy()->setTime(12, 30),
                    'district_id' => $c->block->district_id, 'block_id' => $c->block_id, 'cluster_id' => $c->id,
                    'state_code' => '18', 'district_code' => $c->block->district->code, 'block_code' => $c->block->code, 'cluster_code' => $c->code,
                    'person_met_name' => 'Head Teacher', 'person_met_designation' => 'Head Teacher',
                    'status' => 'submitted', 'completion_pct' => 100, 'questionnaire_version' => Q::VERSION,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::table('inspections')->insertOrIgnore($batch); // a rare officer/school/day clash is skipped
            $ids = DB::table('inspections')->whereIn('inspection_no', array_column($batch, 'inspection_no'))->pluck('id');
            $answers = [];
            foreach ($ids as $id) {
                foreach ($codes as $code => $q) {
                    $answers[] = ['inspection_id' => $id, 'step' => $q['step'], 'question_code' => $code, 'value' => json_encode($this->value($q, $id)), // the column holds JSON
                        'created_at' => $now, 'updated_at' => $now];
                }
            }
            foreach (array_chunk($answers, 2000) as $a) {
                DB::table('inspection_answers')->insert($a);
            }
            $made += count($batch);
        }

        $this->command?->info(sprintf('Load-test data: %d officers (7000000001…, %s), %d schools, %d submitted inspections, %d answers.',
            count($userIds), self::PASSWORD, count($schoolIds), DB::table('inspections')->where('inspection_no', 'like', 'LT/%')->count(),
            DB::table('inspection_answers')->whereIn('inspection_id', DB::table('inspections')->where('inspection_no', 'like', 'LT/%')->select('id'))->count()));
    }

    private function value(array $q, int $id): ?string
    {
        return match ($q['type']) {
            'radio' => (string) array_keys($q['options'])[$id % count($q['options'])],
            'number' => (string) (5 + $id % 40),
            'textarea', 'text' => 'Load test entry '.$id,
            default => null,
        };
    }
}
