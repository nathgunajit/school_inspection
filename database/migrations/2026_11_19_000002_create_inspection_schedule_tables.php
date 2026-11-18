<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New Module 2: map-based inspection scheduling.
 * One schedule per officer per day; its items are the schools in visit order.
 * A school can be on only one officer's plan for a given day (unique school_id + schedule_date).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('officer_id')->constrained('users')->restrictOnDelete();
            $table->date('schedule_date');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();
            $table->decimal('total_distance_km', 8, 2)->default(0);
            $table->unsignedSmallInteger('total_minutes')->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->unique(['officer_id', 'schedule_date'], 'sched_officer_date_unique');
            $table->index(['status', 'schedule_date'], 'sched_status_date_idx');
        });

        Schema::create('inspection_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('inspection_schedules')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->date('schedule_date'); // copy of the schedule's date, for the unique key below
            $table->unsignedSmallInteger('sequence');
            $table->decimal('distance_km', 7, 2)->nullable();     // from the previous stop (or the start point)
            $table->unsignedSmallInteger('travel_minutes')->nullable();
            $table->string('planned_arrival', 5)->nullable();      // HH:MM
            $table->string('status', 20)->default('planned');     // planned, inspected, skipped
            $table->foreignId('inspection_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['schedule_id', 'school_id'], 'sched_item_school_unique');
            $table->unique(['school_id', 'schedule_date'], 'sched_item_school_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_schedule_items');
        Schema::dropIfExists('inspection_schedules');
    }
};
