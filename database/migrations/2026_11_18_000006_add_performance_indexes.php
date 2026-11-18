<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modification V4 item 5: indexes for the queries the application actually runs.
 * Each index is named after the screen or job it serves. See DB_INDEXING.md for the full report.
 * Only adds indexes (no data change); safe to run on a live database. Skips any index that already exists.
 */
return new class extends Migration
{
    /** table => [index name => columns] */
    private const INDEXES = [
        'inspections' => [
            // start rules (InspectionStartGuard), school page history, search badges
            'insp_school_status_date_idx' => ['school_id', 'status', 'inspection_date'],
            // My Inspections, dashboard, profile counts, own export
            'insp_officer_status_date_idx' => ['officer_id', 'status', 'inspection_date'],
            // area reports and Excel export filtered by jurisdiction, status and period
            'insp_district_status_date_idx' => ['district_id', 'status', 'inspection_date'],
            'insp_block_status_date_idx' => ['block_id', 'status', 'inspection_date'],
            'insp_cluster_status_date_idx' => ['cluster_id', 'status', 'inspection_date'],
            // nightly inspections:expire-drafts (status = draft and started_at < …)
            'insp_status_started_idx' => ['status', 'started_at'],
        ],
        'schools' => [
            // Find School within a jurisdiction, ordered by status then name
            'schools_district_status_name_idx' => ['district_id', 'status', 'name'],
            'schools_block_status_name_idx' => ['block_id', 'status', 'name'],
            'schools_cluster_status_name_idx' => ['cluster_id', 'status', 'name'],
        ],
        'inspection_answers' => [
            // question-wise reports and the Excel export columns
            'answers_question_code_idx' => ['question_code'],
        ],
        'audit_logs' => [
            // audit log screen: newest first, date range, by user, by IP; retention by date
            'audit_created_idx' => ['created_at'],
            'audit_user_created_idx' => ['user_id', 'created_at'],
            'audit_ip_idx' => ['ip_address'],
        ],
        'user_jurisdictions' => [
            // user lists and approvals scoped by jurisdiction (current posting has end_date null)
            'uj_district_end_idx' => ['district_id', 'end_date'],
            'uj_block_end_idx' => ['block_id', 'end_date'],
            'uj_cluster_end_idx' => ['cluster_id', 'end_date'],
        ],
        'registration_requests' => [
            // approval queue of one approver
            'reg_approver_status_idx' => ['approver_id', 'status'],
        ],
        'mobile_change_requests' => [
            // pending mobile-change queue and "pending request" on the profile
            'mcr_status_created_idx' => ['status', 'created_at'],
            'mcr_user_status_idx' => ['user_id', 'status'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
