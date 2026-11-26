<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 26-11-2026: the Today's Schools settings a day's list was made with (DailyDisplaySetting::listStamp()). When the
 * settings change, the list is refreshed on the officer's next view, the same day. Null = made before this column:
 * stamped on the next view without changing the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_school_list_days', function (Blueprint $table) {
            $table->string('setting_stamp', 40)->nullable()->after('list_date');
        });
    }

    public function down(): void
    {
        Schema::table('daily_school_list_days', function (Blueprint $table) {
            $table->dropColumn('setting_stamp');
        });
    }
};
