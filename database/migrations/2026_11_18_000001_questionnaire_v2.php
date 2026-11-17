<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Questionnaire v2 ("Modification of the ApplicationV1.docx"):
 * absentee type/reason, classroom performance rows, fund by year in lakh, evidence source.
 * Additive: v1 columns are kept (made nullable) so legacy rows stay readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_absent_teachers', function (Blueprint $table) {
            $table->string('teacher_type', 20)->nullable()->after('teacher_name');
            $table->string('reason', 20)->nullable()->after('teacher_type');
            $table->string('status', 30)->nullable()->change();
        });

        Schema::create('inspection_classroom_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->string('teacher_name', 120);
            $table->string('class', 4);
            $table->string('subject', 100);
            $table->boolean('tlm_used');
            $table->string('participation', 20);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::table('inspection_fund_positions', function (Blueprint $table) {
            foreach (['asg_received', 'asg_expenditure', 'mdm_received', 'mdm_expenditure', 'govt_received', 'govt_expenditure', 'other_received', 'other_expenditure'] as $col) {
                $table->decimal($col, 10, 2)->default(0);
            }
            $table->decimal('amount_received', 12, 2)->nullable()->default(null)->change();
            $table->decimal('amount_utilised', 12, 2)->nullable()->default(null)->change();
            $table->unique(['inspection_id', 'financial_year'], 'fund_inspection_year_unique');
        });

        Schema::table('inspection_evidence', function (Blueprint $table) {
            $table->enum('source', ['camera', 'gallery'])->default('camera')->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_evidence', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('inspection_fund_positions', function (Blueprint $table) {
            $table->dropUnique('fund_inspection_year_unique');
            $table->dropColumn(['asg_received', 'asg_expenditure', 'mdm_received', 'mdm_expenditure', 'govt_received', 'govt_expenditure', 'other_received', 'other_expenditure']);
        });
        Schema::dropIfExists('inspection_classroom_observations');
        Schema::table('inspection_absent_teachers', fn (Blueprint $t) => $t->dropColumn(['teacher_type', 'reason']));
    }
};
