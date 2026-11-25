<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 25-11-2026: a notice can be shown in the Dashboard "Latest Notice" ticker (homepage) or not. Ticked when written;
 * the sender can change it on the notice page. Existing notices stay on (default true).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notices', fn (Blueprint $t) => $t->boolean('show_on_home')->default(true)->after('expires_on'));
    }

    public function down(): void
    {
        Schema::table('notices', fn (Blueprint $t) => $t->dropColumn('show_on_home'));
    }
};
