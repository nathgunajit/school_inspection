<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 25-11-2026, database optimisation: indexes for the queries EXPLAIN showed reading the whole
 * School Master (44,197 schools) or sorting it on disk. See Product Details/DB_INDEXING.md section 9.
 * Only adds indexes (no data change); safe on a live database. Skips any index that already exists.
 */
return new class extends Migration
{
    /** table => [index name => columns] */
    private const INDEXES = [
        'schools' => [
            // state-wide counts of active schools (dashboard, Inspections in My Area tiles) and the
            // state-wide school suggestions (status = active, ordered by name, first 10 matches)
            'schools_status_deleted_name_idx' => ['status', 'deleted_at', 'name'],
            // Inspection Coverage: active schools per district / block / cluster, read from the index alone
            'schools_status_deleted_area_idx' => ['status', 'deleted_at', 'district_id', 'block_id', 'cluster_id'],
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
