<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Location Master (PRD 7.1) and master values (PRD 13 #9).
 * Runs before users because users reference designations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_values', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['designation', 'management', 'category', 'photo_category', 'fund_category']);
            $table->string('code', 40);
            $table->string('label', 150);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['type', 'code']);
        });

        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('state_id')->constrained()->restrictOnDelete();
            $table->string('code', 10)->unique();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained()->restrictOnDelete();
            $table->string('code', 12)->unique();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('clusters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('block_id')->constrained()->restrictOnDelete();
            $table->string('code', 15)->unique();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clusters');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('states');
        Schema::dropIfExists('master_values');
    }
};
