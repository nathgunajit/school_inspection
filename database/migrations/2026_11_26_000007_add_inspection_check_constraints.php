<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 26-11-2026: inspection entry rules enforced by the database as well as the form and the server
 * (Product Details/INSPECTION_VALIDATION.md). Same pattern as 2026_11_19_000007 (mobile CHECK):
 * MySQL 8.0.16+ / MariaDB 10.2+ only; SQLite (tests) cannot add a CHECK to an existing table.
 * Existing rows are counted first; a rule that old rows break is skipped and reported (never fails the deploy),
 * so the old data can be corrected and the migration run again with `migrate:refresh --path=...`.
 */
return new class extends Migration
{
    /** [table, constraint name, SQL condition] */
    private function checks(): array
    {
        $amount = fn (string $c) => "(`$c` IS NULL OR (`$c` >= 0 AND `$c` <= 99999))";
        $amounts = ['asg_received', 'asg_expenditure', 'mdm_received', 'mdm_expenditure', 'govt_received', 'govt_expenditure',
            'other_received', 'other_expenditure', 'amount_received', 'amount_utilised'];
        $lat = fn (string $c) => "(`$c` IS NULL OR `$c` BETWEEN -90 AND 90)";
        $lng = fn (string $c) => "(`$c` IS NULL OR `$c` BETWEEN -180 AND 180)";
        $acc = fn (string $c) => "(`$c` IS NULL OR `$c` >= 0)";

        return [
            ['inspection_fund_positions', 'chk_fund_amounts', implode(' AND ', array_map($amount, $amounts))],
            ['inspection_fund_positions', 'chk_fund_financial_year', "`financial_year` REGEXP '^FY-[0-9]{4}-[0-9]{2}$'"],
            ['inspections', 'chk_inspections_completion_pct', '`completion_pct` <= 100'],
            ['inspections', 'chk_inspections_location', implode(' AND ', [$lat('start_lat'), $lng('start_lng'), $acc('start_accuracy'), $lat('submit_lat'), $lng('submit_lng'), $acc('submit_accuracy')])],
            ['inspection_evidence', 'chk_evidence_location', implode(' AND ', [$lat('latitude'), $lng('longitude'), $acc('accuracy')])],
            ['inspection_absent_teachers', 'chk_absent_teacher_type', "(`teacher_type` IS NULL OR `teacher_type` IN ('regular','contractual','tutor'))"],
            ['inspection_absent_teachers', 'chk_absent_reason', "(`reason` IS NULL OR `reason` IN ('CL','EL','MATL','CCL','SL','MEDL','UL'))"],
            ['inspection_absent_teachers', 'chk_absent_teacher_name', 'CHAR_LENGTH(TRIM(`teacher_name`)) >= 2'],
            ['inspection_classroom_observations', 'chk_classroom_class', "`class` IN ('I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII')"],
            ['inspection_classroom_observations', 'chk_classroom_participation', "`participation` IN ('satisfactory','not_satisfactory')"],
            ['inspection_classroom_observations', 'chk_classroom_names', 'CHAR_LENGTH(TRIM(`teacher_name`)) >= 2 AND CHAR_LENGTH(TRIM(`subject`)) >= 2'],
            ['inspection_answers', 'chk_answers_step', '`step` BETWEEN 1 AND 7'],
        ];
    }

    private function supported(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function up(): void
    {
        if (! $this->supported()) {
            return;
        }
        foreach ($this->checks() as [$table, $name, $sql]) {
            if ($this->exists($table, $name)) {
                continue;
            }
            $bad = DB::table($table)->whereRaw("NOT ($sql)")->count();
            if ($bad > 0) {
                $msg = "Inspection CHECK $name skipped: $bad existing row(s) in $table break it ($sql). Correct them and run this migration again.";
                Log::warning($msg);
                fwrite(STDERR, "  ! $msg\n");

                continue;
            }
            DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `$name` CHECK ($sql)");
        }
    }

    public function down(): void
    {
        if (! $this->supported()) {
            return;
        }
        foreach ($this->checks() as [$table, $name]) {
            if ($this->exists($table, $name)) {
                DB::statement("ALTER TABLE `$table` DROP CONSTRAINT `$name`");
            }
        }
    }

    private function exists(string $table, string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)->where('CONSTRAINT_TYPE', 'CHECK')->exists();
    }
};
