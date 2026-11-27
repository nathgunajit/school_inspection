<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 27-11-2026: the officer's date of birth (asked at registration; older officers have none). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'date_of_birth')) {
            Schema::table('users', fn (Blueprint $t) => $t->date('date_of_birth')->nullable()->after('gender'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'date_of_birth')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('date_of_birth'));
        }
    }
};
