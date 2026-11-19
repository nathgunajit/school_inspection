<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index review on the load-test volume (Product Details/DB_INDEXING.md section 8): no query reads inspection_answers by question_code alone.
 * Every read goes through inspection_id (wizard, report, PDF, Excel export), which the unique key (inspection_id, question_code) serves.
 * answers_question_code_idx was 17.5 MB at 650,000 answers and was written on every auto-save, so it is removed.
 * down() restores it. Phase 2 question-wise reports should join through inspections (district/status/date indexes) instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('inspection_answers', 'answers_question_code_idx')) {
            Schema::table('inspection_answers', fn (Blueprint $t) => $t->dropIndex('answers_question_code_idx'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasIndex('inspection_answers', 'answers_question_code_idx')) {
            Schema::table('inspection_answers', fn (Blueprint $t) => $t->index(['question_code'], 'answers_question_code_idx'));
        }
    }
};
