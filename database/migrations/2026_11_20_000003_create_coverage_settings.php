<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inspection Coverage Report: the coverage period the administrator configures.
 * district_id null = state default (State Admin); a district row overrides it for that district
 * (District officer with the Manage users right). verify_radius_m is read from the state row only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coverage_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->string('period_type', 12)->default('monthly');      // daily | weekly | monthly | quarterly | custom
            $table->date('custom_from')->nullable();
            $table->date('custom_to')->nullable();
            $table->string('inspection_cycle', 12)->default('quarterly'); // monthly | quarterly | half_yearly | yearly
            $table->unsignedSmallInteger('verify_radius_m')->default(500);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('coverage_settings')->insert([
            'district_id' => null, 'period_type' => 'monthly', 'inspection_cycle' => 'quarterly', 'verify_radius_m' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_settings');
    }
};
