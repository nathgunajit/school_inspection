<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modification V7 (item 5): an officer who picks the "Other" designation types it in.
 * The master value `designation / other` already exists (MasterValueSeeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('designation_other', 120)->nullable()->after('designation_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('designation_other');
        });
    }
};
