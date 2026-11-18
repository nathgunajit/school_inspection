<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** New Module 1 (Map View): optional centre coordinates for a Cluster, like districts and blocks. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clusters', function (Blueprint $t) {
            $t->decimal('latitude', 10, 7)->nullable()->after('name');
            $t->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('clusters', fn (Blueprint $t) => $t->dropColumn(['latitude', 'longitude']));
    }
};
