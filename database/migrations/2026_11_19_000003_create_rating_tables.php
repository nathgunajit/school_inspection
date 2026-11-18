<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New Module 3: automatic inspection rating.
 *  rating_schemes      versioned scoring set-ups; exactly one is active. Saving changes creates a new version,
 *                      so a rating always shows the scheme it was calculated with.
 *  rating_criteria     what is scored: a question answer (code + points per answer) or a derived metric, with a weight
 *  inspection_ratings  one row per rated inspection: overall and section scores, band, strengths and weaknesses
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rating_schemes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('name', 120);
            $table->boolean('is_active')->default(false);
            $table->json('bands');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('rating_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheme_id')->constrained('rating_schemes')->cascadeOnDelete();
            $table->string('section', 20);           // step key: condition, strength, absentee, classroom, fund, governance
            $table->string('code', 40);              // question code (kind=answer) or metric key (kind=metric)
            $table->string('label', 200);
            $table->string('kind', 10);              // answer | metric
            $table->decimal('weight', 6, 2)->default(1);
            $table->json('points')->nullable();      // answer value => 0..1 (kind=answer)
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unique(['scheme_id', 'code']);
        });

        Schema::create('inspection_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('scheme_id')->constrained('rating_schemes')->restrictOnDelete();
            $table->decimal('obtained', 8, 2)->default(0);
            $table->decimal('applicable', 8, 2)->default(0);
            $table->decimal('score_percent', 5, 2)->nullable();  // null = nothing applicable (not rated)
            $table->string('band', 40)->nullable();
            $table->string('color', 20)->nullable();
            $table->json('sections');
            $table->json('details');
            $table->json('strengths');
            $table->json('weaknesses');
            $table->timestamp('calculated_at');
            $table->timestamps();
            $table->index(['score_percent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_ratings');
        Schema::dropIfExists('rating_criteria');
        Schema::dropIfExists('rating_schemes');
    }
};
