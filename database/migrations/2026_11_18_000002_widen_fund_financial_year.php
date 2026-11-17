<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v2 financial years look like "FY-2026-27" (10 chars); v1 column was VARCHAR(9). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_fund_positions', function (Blueprint $table) {
            $table->string('financial_year', 12)->change();
        });
    }

    public function down(): void
    {
        Schema::table('inspection_fund_positions', function (Blueprint $table) {
            $table->string('financial_year', 9)->change();
        });
    }
};
