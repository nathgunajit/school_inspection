<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Headquarters coordinates for District and Block in the Location Master (optional). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['districts', 'blocks'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->decimal('latitude', 10, 7)->nullable()->after('name');
                $t->decimal('longitude', 10, 7)->nullable()->after('latitude');
            });
        }
    }

    public function down(): void
    {
        foreach (['districts', 'blocks'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['latitude', 'longitude']));
        }
    }
};
