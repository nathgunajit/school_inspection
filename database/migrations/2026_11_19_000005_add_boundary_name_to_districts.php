<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V6 Module 7: which KML district outline (public/geo/districts/*.geojson) belongs to a District Master row.
 * Null means "match by name". Set in the Location Master or by `php artisan map:boundaries --assign`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('districts', function (Blueprint $table) {
            $table->string('boundary_name', 80)->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('districts', function (Blueprint $table) {
            $table->dropColumn('boundary_name');
        });
    }
};
