<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modification V15 (26-11-2026), Today's Schools:
 *  - daily_display_settings: Skip on / off, the next-day notice and the pending reminder (State-only values).
 *  - daily_school_lists.carried_to: a skipped row that was already put on a later day's list.
 *  - daily_school_list_days: notice / reminder sent (once a day) and the lock once an inspection starts, with the
 *    State Administrator's unlock (who, when, why).
 *  - notifications: Laravel's database notifications (the in-app bell).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_display_settings', function (Blueprint $table) {
            $table->boolean('allow_skip')->default(true)->after('skip_blocked');
            $table->boolean('notify_enabled')->default(true)->after('allow_skip');
            $table->string('notify_time', 5)->default('18:00')->after('notify_enabled');   // H:i, the evening before
            $table->boolean('reminder_enabled')->default(true)->after('notify_time');
            $table->string('reminder_time', 5)->default('14:00')->after('reminder_enabled'); // H:i, on the day
        });

        Schema::table('daily_school_lists', function (Blueprint $table) {
            $table->date('carried_to')->nullable()->after('carried_from');
        });

        Schema::table('daily_school_list_days', function (Blueprint $table) {
            $table->dateTime('notified_at')->nullable()->after('setting_stamp');
            $table->dateTime('reminded_at')->nullable()->after('notified_at');
            $table->dateTime('locked_at')->nullable()->after('reminded_at');
            $table->dateTime('unlocked_at')->nullable()->after('locked_at');
            $table->foreignId('unlocked_by')->nullable()->after('unlocked_at')->constrained('users')->nullOnDelete();
            $table->string('unlock_reason', 200)->nullable()->after('unlocked_by');
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::table('daily_school_list_days', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unlocked_by');
            $table->dropColumn(['notified_at', 'reminded_at', 'locked_at', 'unlocked_at', 'unlock_reason']);
        });
        Schema::table('daily_school_lists', fn (Blueprint $t) => $t->dropColumn('carried_to'));
        Schema::table('daily_display_settings', fn (Blueprint $t) => $t->dropColumn(['allow_skip', 'notify_enabled', 'notify_time', 'reminder_enabled', 'reminder_time']));
    }
};
