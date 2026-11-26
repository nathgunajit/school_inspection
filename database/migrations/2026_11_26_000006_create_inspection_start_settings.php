<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 26-11-2026: inspection start rules chosen by the State Admin (one row).
 *  repeat_window: how long a school stays blocked for officers of the same level after one of them submitted it
 *                 (month | week | day | none); an open same-level draft always blocks.
 *  cross_level:   true = officers of different levels may inspect the same school, even the same day;
 *                 false = not on the same day.
 * The defaults keep the rules as they were (month, different levels allowed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_start_settings', function (Blueprint $table) {
            $table->id();
            $table->string('repeat_window', 8)->default('month');
            $table->boolean('cross_level')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('inspection_start_settings')->insert(['repeat_window' => 'month', 'cross_level' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_start_settings');
    }
};
