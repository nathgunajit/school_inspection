<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** 28-11-2026: the no-internet declaration (NET_02) moved from step 1 to the Declaration section of the last step (7). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('inspection_answers')->where('question_code', 'NET_02')->update(['step' => 7]);
    }

    public function down(): void
    {
        DB::table('inspection_answers')->where('question_code', 'NET_02')->update(['step' => 1]);
    }
};
