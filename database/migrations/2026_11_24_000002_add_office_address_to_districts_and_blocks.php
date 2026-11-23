<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 23-11-2026: District and Block offices on the Map View. latitude / longitude of a district or block are its
 * office location (also the schedule start point); office_address is printed in the map popup.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['districts', 'blocks'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('office_address', 255)->nullable()->after('longitude'));
        }
    }

    public function down(): void
    {
        foreach (['districts', 'blocks'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('office_address'));
        }
    }
};
