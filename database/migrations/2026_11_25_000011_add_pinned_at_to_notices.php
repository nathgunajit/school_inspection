<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 25-11-2026: "Latest Notification". A sender pins one of their notices (one per sender); the Dashboard top strip shows
 * the newest pinned notice the officer can see, whatever its date, else the newest one ticked for the homepage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notices', function (Blueprint $t) {
            $t->timestamp('pinned_at')->nullable()->after('show_on_home');
            $t->index('pinned_at');
        });
    }

    public function down(): void
    {
        Schema::table('notices', function (Blueprint $t) {
            $t->dropIndex(['pinned_at']);
            $t->dropColumn('pinned_at');
        });
    }
};
