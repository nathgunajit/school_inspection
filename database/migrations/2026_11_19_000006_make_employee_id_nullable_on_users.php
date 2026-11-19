<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modification V7 (item 1): Employee ID is no longer mandatory at registration.
 * The column stays unique; several NULLs are allowed (MySQL and SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id', 40)->nullable()->change();
        });
        DB::table('users')->where('employee_id', '')->update(['employee_id' => null]);
    }

    public function down(): void
    {
        DB::table('users')->whereNull('employee_id')->update(['employee_id' => DB::raw("CONCAT('EMP-', id)")]);
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id', 40)->nullable(false)->change();
        });
    }
};
