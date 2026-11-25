<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modification V14 (updated 25-11-2026), item 5: "Today's schools", the schools shown to a CRCC each working day.
 * daily_display_settings: district_id null = state default (State Admin); a district row overrides it (District officer
 * with Manage users), like coverage_settings. daily_school_list_days marks a generated day (generated once, stable all day);
 * daily_school_lists holds the schools (auto, carried forward or assigned manually).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_display_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->json('levels');                                       // officer levels that get the list (default: cluster)
            $table->unsignedTinyInteger('daily_limit')->default(2);
            $table->string('selection', 10)->default('priority');         // priority | random
            $table->boolean('prefer_not_inspected')->default(true);
            $table->boolean('prefer_low_rating')->default(true);
            $table->unsignedTinyInteger('low_rating_below')->default(50); // %
            $table->boolean('prefer_issues')->default(true);
            $table->boolean('prefer_less_visited')->default(true);
            $table->boolean('carry_forward')->default(true);
            $table->unsignedSmallInteger('recent_days')->default(30);     // not shown again within N days of an inspection
            $table->json('weekly_off');                                   // 0 = Sunday … 6 = Saturday
            $table->boolean('skip_blocked')->default(true);               // skip schools planned / being inspected / done this month by another officer
            $table->unsignedSmallInteger('max_distance_km')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('daily_display_settings')->insert([
            'district_id' => null, 'enabled' => true, 'levels' => json_encode(['cluster']), 'daily_limit' => 2, 'selection' => 'priority',
            'prefer_not_inspected' => true, 'prefer_low_rating' => true, 'low_rating_below' => 50, 'prefer_issues' => true,
            'prefer_less_visited' => true, 'carry_forward' => true, 'recent_days' => 30, 'weekly_off' => json_encode([0]), 'skip_blocked' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::create('daily_school_list_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('officer_id')->constrained('users')->cascadeOnDelete();
            $table->date('list_date');
            $table->timestamps();
            $table->unique(['officer_id', 'list_date']);
        });

        Schema::create('daily_school_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('officer_id')->constrained('users')->cascadeOnDelete();
            $table->date('list_date');
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('reason', 20);                     // not_inspected | low_rating | issue | less_visited | random | carried | manual
            $table->string('source', 10);                     // auto | carried | manual
            $table->string('status', 10)->default('pending'); // pending | visited | skipped | carried
            $table->date('carried_from')->nullable();
            $table->string('skip_reason', 200)->nullable();
            $table->foreignId('inspection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['officer_id', 'list_date', 'school_id']);
            $table->unique(['school_id', 'list_date']);
            $table->index(['officer_id', 'status', 'list_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_school_lists');
        Schema::dropIfExists('daily_school_list_days');
        Schema::dropIfExists('daily_display_settings');
    }
};
