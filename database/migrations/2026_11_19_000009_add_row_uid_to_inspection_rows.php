<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Working without network (T2): a row added offline is sent again when the network returns. The browser gives
 * each new row a `row_uid`, so a replayed request (e.g. the first one reached the server but its answer was
 * lost) never creates a duplicate. Older rows keep NULL (several NULLs are allowed by the unique key).
 */
return new class extends Migration
{
    private const TABLES = ['inspection_absent_teachers', 'inspection_classroom_observations'];

    public function up(): void
    {
        foreach (self::TABLES as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->uuid('row_uid')->nullable()->after('inspection_id');
                $table->unique(['inspection_id', 'row_uid']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropUnique(['inspection_id', 'row_uid']);
                $table->dropColumn('row_uid');
            });
        }
    }
};
