<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AC / PC master: Assembly (AC) and Parliamentary (PC, Lok Sabha) constituencies, each in its own table
 * with its own code. Schools link to both (pc_id follows the AC). Replaces the free-text columns
 * schools.assembly_constituency / schools.lok_sabha added earlier the same day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parliamentary_constituencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();          // e.g. PC1801
            $table->string('name', 60)->unique();          // e.g. KOKRAJHAR (ST)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('assembly_constituencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();          // e.g. AC18002
            $table->string('name', 60);                    // e.g. DOTMA (ST)
            $table->foreignId('pc_id')->constrained('parliamentary_constituencies')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('name');
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['assembly_constituency', 'lok_sabha']);
        });
        Schema::table('schools', function (Blueprint $table) {
            $table->foreignId('ac_id')->nullable()->after('medium')->constrained('assembly_constituencies')->restrictOnDelete();
            $table->foreignId('pc_id')->nullable()->after('ac_id')->constrained('parliamentary_constituencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ac_id');
            $table->dropConstrainedForeignId('pc_id');
        });
        Schema::table('schools', function (Blueprint $table) {
            $table->string('assembly_constituency', 60)->nullable()->after('medium');
            $table->string('lok_sabha', 40)->nullable()->after('assembly_constituency');
        });
        Schema::dropIfExists('assembly_constituencies');
        Schema::dropIfExists('parliamentary_constituencies');
    }
};
