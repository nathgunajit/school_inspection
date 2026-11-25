<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 25-11-2026: the State Administrator empties test data in separate groups: inspections (with Today's Schools),
 * Inspection Schedules, the login log and the audit log. Every table is shown first with its row count and SQL.
 *
 * Available on the local server only: never in production, and only while TEST_DATA_CLEANUP is on
 * (config inspection.test_data_cleanup, default on for APP_ENV local / testing). When off, the page answers 404.
 */
class TestDataCleaner
{
    public const GROUPS = [
        'inspections' => [
            'title' => 'Inspections & Today\'s Schools', 'icon' => 'bi-clipboard-check',
            'text' => 'Every inspection (drafts and submitted) with its answers, rows, photographs and rating, and the Today\'s Schools lists. Schedule stops stay, without their inspection link.',
            'pre_sql' => 'UPDATE inspections SET prefilled_from_id = NULL;',
            'tables' => [
                'inspection_classroom_tlm' => 'TLM ticks of the classroom rows (step 4)',
                'inspection_classroom_observations' => 'Classroom observation rows (step 4)',
                'inspection_absent_teachers' => 'Absent teacher rows (step 3)',
                'inspection_fund_positions' => 'Fund rows by financial year (step 5)',
                'inspection_answers' => 'Every questionnaire answer',
                'inspection_remarks' => 'Remarks and suggestions',
                'inspection_ratings' => 'Calculated inspection ratings',
                'inspection_evidence' => 'Photographs (rows; the image files are deleted too)',
                'daily_school_lists' => 'Today\'s Schools rows',
                'daily_school_list_days' => 'Today\'s Schools "list made" day markers',
                'inspections' => 'Inspections, drafts and submitted (also soft-deleted ones)',
            ],
        ],
        'schedules' => [
            'title' => 'Inspection Schedules', 'icon' => 'bi-calendar-week',
            'text' => 'Every inspection plan with its school stops and order letters. Inspections are not touched.',
            'pre_sql' => null,
            'tables' => [
                'inspection_schedule_items' => 'Planned school stops',
                'inspection_schedules' => 'Inspection plans (their order files are deleted too)',
            ],
        ],
        'login_logs' => [
            'title' => 'Login log', 'icon' => 'bi-person-lock',
            'text' => 'Every sign-in, sign-out and time-out record. Login Monitoring and its Daily report start empty.',
            'pre_sql' => null,
            'tables' => ['user_login_logs' => 'Sign-in / sign-out records (Login Monitoring)'],
        ],
        'audit_logs' => [
            'title' => 'Audit log', 'icon' => 'bi-shield-check',
            'text' => 'Every audit entry. A copy is saved first as an Excel archive (Audit Log page → Archives); the clean-up itself is then recorded as the first new entries.',
            'pre_sql' => null,
            'tables' => ['audit_logs' => 'Audit entries (archived to Excel before they are deleted)'],
        ],
    ];

    /** Only on the local server: never in production, and only with TEST_DATA_CLEANUP on. */
    public static function enabled(): bool
    {
        return ! app()->isProduction() && (bool) config('inspection.test_data_cleanup');
    }

    /** @return array<string, array{key:string, title:string, icon:string, text:string, pre_sql:?string, rows:list<array>, total:int, files:int}> */
    public function preview(): array
    {
        $out = [];
        foreach (self::GROUPS as $key => $g) {
            $rows = [];
            foreach ($g['tables'] as $table => $label) {
                $rows[] = ['table' => $table, 'label' => $label, 'rows' => DB::table($table)->count(), 'sql' => $this->sql($key, $table)];
            }
            $out[$key] = ['key' => $key] + array_diff_key($g, ['tables' => 1])
                + ['rows' => $rows, 'total' => array_sum(array_column($rows, 'rows')), 'files' => count($this->files($key))];
        }

        return $out;
    }

    /** @return array{key:string, title:string, rows:list<array>, total:int, files:int, archive:?string} */
    public function run(string $group, ?User $actor = null): array
    {
        abort_unless(self::enabled(), 404);
        abort_unless(isset(self::GROUPS[$group]), 404);
        $g = self::GROUPS[$group];
        $files = $this->files($group);
        $sql = [];
        foreach (array_keys($g['tables']) as $table) {
            $sql[$table] = $this->sql($group, $table);
        }
        $removed = [];
        $archive = null;

        if ($group === 'audit_logs') {
            // the one sanctioned audit delete (domain rule 7): Excel archive first, then delete (HousekeepingService)
            $r = app(HousekeepingService::class)->purgeAudit(0, true);
            $removed['audit_logs'] = $r['count'];
            $archive = $r['file'];
        } else {
            DB::transaction(function () use ($g, &$removed) {
                if ($g['pre_sql']) {
                    DB::table('inspections')->whereNotNull('prefilled_from_id')->update(['prefilled_from_id' => null]);
                }
                foreach (array_keys($g['tables']) as $table) {
                    $removed[$table] = DB::table($table)->delete();
                }
            });
        }

        $disk = Storage::disk('private');
        foreach ($files as $path) {
            $disk->delete($path);
        }
        Cache::flush(); // dashboard figures, map data, counts

        AuditLogger::log('test_data_cleaned', null, ['group' => $group, 'tables' => $removed, 'files' => count($files), 'archive' => $archive], $actor);

        $rows = [];
        foreach ($g['tables'] as $table => $label) {
            $rows[] = ['table' => $table, 'label' => $label, 'rows' => $removed[$table] ?? 0, 'sql' => $sql[$table]];
        }

        return ['key' => $group, 'title' => $g['title'], 'rows' => $rows, 'total' => array_sum($removed), 'files' => count($files), 'archive' => $archive];
    }

    private function sql(string $group, string $table): string
    {
        if ($group === 'audit_logs') {
            return 'DELETE FROM audit_logs WHERE id <= '.((int) DB::table('audit_logs')->max('id')).';';
        }

        return "DELETE FROM {$table};";
    }

    /** Files on the private disk that go with the group's rows. */
    private function files(string $group): array
    {
        return match ($group) {
            'inspections' => DB::table('inspection_evidence')->whereNotNull('storage_path')->distinct()->pluck('storage_path')->all(),
            'schedules' => DB::table('inspection_schedules')->whereNotNull('attachment_path')->distinct()->pluck('attachment_path')->all(),
            default => [],
        };
    }
}
