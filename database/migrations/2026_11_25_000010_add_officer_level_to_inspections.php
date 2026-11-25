<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 25-11-2026: the inspecting officer's level, kept with each inspection. Officers of different levels may inspect the same
 * school; an officer sees their own inspections and those of lower levels (App\Support\InspectionVisibility).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $t) {
            $t->string('officer_level', 12)->nullable()->after('officer_id');
            $t->index('officer_level');
        });
        DB::statement('UPDATE inspections SET officer_level = (SELECT level FROM users WHERE users.id = inspections.officer_id)');
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $t) {
            $t->dropIndex(['officer_level']);
            $t->dropColumn('officer_level');
        });
    }
};
