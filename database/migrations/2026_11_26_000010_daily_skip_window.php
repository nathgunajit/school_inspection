<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 26-11-2026: Today's Schools "Skip schools taken by another officer" gets its own period
 * (day / week / month; DailyDisplaySetting::SKIP_WINDOWS). Existing rows keep "month", as the note said.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_display_settings', function (Blueprint $table) {
            $table->string('skip_window', 10)->default('month')->after('skip_blocked');
        });
    }

    public function down(): void
    {
        Schema::table('daily_display_settings', function (Blueprint $table) {
            $table->dropColumn('skip_window');
        });
    }
};
