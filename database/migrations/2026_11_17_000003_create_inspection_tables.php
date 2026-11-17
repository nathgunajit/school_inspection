<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inspections, answers, evidence, remarks (PRD 8, 9, 10, 13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->string('inspection_no', 40)->nullable()->unique();
            $table->foreignId('officer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->date('inspection_date');
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();

            // Location snapshot as on the inspection date (PRD 13)
            $table->foreignId('district_id')->constrained()->restrictOnDelete();
            $table->foreignId('block_id')->constrained()->restrictOnDelete();
            $table->foreignId('cluster_id')->constrained()->restrictOnDelete();
            $table->string('state_code', 10);
            $table->string('district_code', 10);
            $table->string('block_code', 12);
            $table->string('cluster_code', 15);

            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();
            $table->decimal('start_accuracy', 8, 2)->nullable();
            $table->decimal('submit_lat', 10, 7)->nullable();
            $table->decimal('submit_lng', 10, 7)->nullable();
            $table->decimal('submit_accuracy', 8, 2)->nullable();

            $table->string('person_met_name', 120)->nullable();
            $table->string('person_met_designation', 120)->nullable();

            $table->enum('status', ['draft', 'submitted', 'unlocked', 'cancelled'])->default('draft');
            $table->unsignedTinyInteger('completion_pct')->default(0);
            $table->unsignedTinyInteger('questionnaire_version')->default(1);

            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unlocked_at')->nullable();
            $table->text('unlock_reason')->nullable();
            $table->text('cancelled_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['officer_id', 'school_id', 'inspection_date'], 'inspections_officer_school_day_unique');
            $table->index(['status', 'inspection_date']);
            $table->index(['district_id', 'block_id', 'cluster_id']);
        });

        Schema::create('inspection_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step');
            $table->string('question_code', 20); // e.g. GOV_02
            $table->json('value')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['inspection_id', 'question_code']);
        });

        Schema::create('inspection_absent_teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->string('teacher_name', 120);
            $table->enum('status', ['on_leave', 'official_duty', 'absent_without_info']);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('inspection_fund_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->string('financial_year', 9); // 2026-2027
            $table->foreignId('fund_category_id')->nullable()->constrained('master_values')->restrictOnDelete();
            $table->decimal('amount_received', 12, 2)->default(0);
            $table->decimal('amount_utilised', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('inspection_evidence', function (Blueprint $table) {
            $table->id();
            $table->uuid('evidence_uid')->unique();
            $table->foreignId('inspection_id')->constrained()->restrictOnDelete();
            $table->enum('kind', ['photo', 'document'])->default('photo');
            $table->foreignId('category_id')->nullable()->constrained('master_values')->restrictOnDelete();
            $table->string('question_code', 20)->nullable();
            $table->string('caption', 150)->nullable();
            $table->timestamp('taken_at')->nullable();      // device time
            $table->timestamp('received_at')->nullable();   // server time
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->string('no_location_reason', 255)->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime', 60)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->string('storage_path')->nullable();
            $table->char('sha256', 64)->nullable()->index();
            $table->enum('status', ['pending', 'uploaded', 'failed'])->default('pending');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['inspection_id', 'kind', 'status']);
        });

        Schema::create('inspection_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['suggestion', 'general', 'classroom_note', 'inspection_remark']);
            $table->unsignedTinyInteger('sort')->default(0);
            $table->text('body');
            $table->timestamps();
            $table->index(['inspection_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_remarks');
        Schema::dropIfExists('inspection_evidence');
        Schema::dropIfExists('inspection_fund_positions');
        Schema::dropIfExists('inspection_absent_teachers');
        Schema::dropIfExists('inspection_answers');
        Schema::dropIfExists('inspections');
    }
};
