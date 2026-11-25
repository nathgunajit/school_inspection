<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 25-11-2026: the State Administrator removes every test inspection (drafts and submitted, with answers, rows, photos and
 * ratings), the Inspection Schedules and the Today's Schools lists. Users, masters, notices, holidays, settings and the
 * audit log stay. Refused in production; audited `test_data_cleaned`.
 */
class TestDataCleaner
{
    /** Delete order: children first (inspection_evidence restricts the inspection delete, so it goes explicitly). */
    public const TABLES = [
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
        'inspection_schedule_items' => 'Planned school stops',
        'inspection_schedules' => 'Inspection plans (their order files are deleted too)',
        'inspections' => 'Inspections, drafts and submitted (also soft-deleted ones)',
    ];

    /** Shown as kept on the page. */
    public const KEPT = ['users', 'user_jurisdictions', 'schools', 'districts / blocks / clusters', 'assembly / parliamentary constituencies',
        'tlm_items', 'rating_schemes', 'notices', 'holidays', 'daily_display_settings', 'coverage_settings', 'audit_logs', 'user_login_logs'];

    public const PRE_SQL = 'UPDATE inspections SET prefilled_from_id = NULL;';

    /** @return array{rows: list<array{table:string,label:string,rows:int,sql:string}>, total:int, files:int, pre_sql:string} */
    public function preview(): array
    {
        $rows = [];
        foreach (self::TABLES as $table => $label) {
            $rows[] = ['table' => $table, 'label' => $label, 'rows' => DB::table($table)->count(), 'sql' => "DELETE FROM {$table};"];
        }

        return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'rows')), 'files' => count($this->files()), 'pre_sql' => self::PRE_SQL];
    }

    /** @return array{rows: list<array{table:string,label:string,rows:int,sql:string}>, total:int, files:int} */
    public function run(?User $actor = null): array
    {
        abort_if(app()->isProduction(), 403, 'Test data cannot be cleaned in production.');

        $files = $this->files();
        $removed = [];
        DB::transaction(function () use (&$removed) {
            DB::table('inspections')->whereNotNull('prefilled_from_id')->update(['prefilled_from_id' => null]);
            foreach (array_keys(self::TABLES) as $table) {
                $removed[$table] = DB::table($table)->delete();
            }
        });

        $disk = Storage::disk('private');
        foreach ($files as $path) {
            $disk->delete($path);
        }
        Cache::flush(); // dashboard figures, map data, counts

        AuditLogger::log('test_data_cleaned', null, ['tables' => $removed, 'files' => count($files)], $actor);

        $rows = [];
        foreach (self::TABLES as $table => $label) {
            $rows[] = ['table' => $table, 'label' => $label, 'rows' => $removed[$table], 'sql' => "DELETE FROM {$table};"];
        }

        return ['rows' => $rows, 'total' => array_sum($removed), 'files' => count($files)];
    }

    /** Photo files and schedule order files on the private disk. */
    private function files(): array
    {
        return array_values(array_unique(array_merge(
            DB::table('inspection_evidence')->whereNotNull('storage_path')->pluck('storage_path')->all(),
            DB::table('inspection_schedules')->whereNotNull('attachment_path')->pluck('attachment_path')->all(),
        )));
    }
}
