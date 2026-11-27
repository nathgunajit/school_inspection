<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 27-11-2026: an email address belongs to one user. Stored emails are trimmed and lower-cased
 * (empty = null), then a unique index is added (many nulls stay allowed). If duplicates are
 * found the index is skipped with a warning, so a deploy never fails.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('email', '')->update(['email' => null]);
        DB::table('users')->whereNotNull('email')->update(['email' => DB::raw('LOWER(TRIM(email))')]);
        DB::table('users')->where('email', '')->update(['email' => null]);

        $dupes = DB::table('users')->whereNotNull('email')->select('email')
            ->groupBy('email')->havingRaw('COUNT(*) > 1')->pluck('email');
        if ($dupes->isNotEmpty()) {
            $msg = 'users.email unique index skipped: duplicate addresses '.$dupes->implode(', ').'. Fix them, then add the index (php artisan migrate:refresh --path=database/migrations/2026_11_27_000002_users_email_unique.php).';
            Log::warning($msg);
            echo $msg.PHP_EOL;

            return;
        }

        Schema::table('users', fn (Blueprint $t) => $t->unique('email', 'users_email_unique'));
    }

    public function down(): void
    {
        if (Schema::hasIndex('users', 'users_email_unique')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropUnique('users_email_unique'));
        }
    }
};
