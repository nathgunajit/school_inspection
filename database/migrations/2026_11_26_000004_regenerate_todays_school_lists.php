<?php

use App\Services\DailySchoolListService;
use Illuminate\Database\Migrations\Migration;

/**
 * 26-11-2026: carried-over pending schools used to fill the whole daily limit, so a CRCC with 2 pending schools got
 * no new school. Today's lists made that way are reopened: the next view adds the new schools (rows already there stay).
 */
return new class extends Migration
{
    public function up(): void
    {
        DailySchoolListService::reopenCarriedOnlyDays(today()->toDateString());
    }

    public function down(): void
    {
        // nothing to undo: the lists are only completed
    }
};
