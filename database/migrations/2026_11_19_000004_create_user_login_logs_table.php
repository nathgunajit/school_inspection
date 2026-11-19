<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V6 Module 6: login monitoring for the State Administrator.
 * One row per sign-in attempt. A successful sign-in row is updated with last_seen_at and logout_at,
 * so this is an operational table; the append-only audit_logs keep their login / login_failed entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_login_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mobile', 15)->nullable();          // as typed, also for unknown numbers
            $table->string('status', 12);                       // success, failed, locked, denied
            $table->string('reason', 60)->nullable();           // why a failed / denied attempt was refused
            $table->string('session_id', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('browser', 40)->nullable();
            $table->string('platform', 40)->nullable();
            $table->string('device', 10)->nullable();           // mobile, tablet, desktop
            $table->timestamp('login_at')->useCurrent();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            $table->string('logout_reason', 20)->nullable();    // manual
            $table->index('login_at');
            $table->index(['user_id', 'login_at']);
            $table->index(['status', 'login_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_login_logs');
    }
};
