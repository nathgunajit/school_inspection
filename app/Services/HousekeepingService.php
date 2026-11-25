<?php

namespace App\Services;

use App\Exports\AuditArchiveExport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Modification V13 item 4: audit log and cache clean-up, by the State Admin or the monthly schedule.
 *
 * Audit entries older than config('inspection.audit_retention_days') are first written to an Excel
 * archive on the private disk (audit-archive/), then deleted. This is the only code that removes
 * audit rows; AuditLog model events still refuse any other update or delete.
 */
class HousekeepingService
{
    public const ARCHIVE_DIR = 'audit-archive';

    /**
     * @param  bool  $everything  25-11-2026, Clean Test Data (local server only): every entry up to now, the current second included
     * @return array{count: int, cutoff: string, file: ?string}
     */
    public function purgeAudit(?int $days = null, bool $everything = false): array
    {
        set_time_limit(0);
        $days ??= (int) config('inspection.audit_retention_days', 30);
        $cutoff = $everything ? now()->addSecond() : now()->subDays(max(0, $days));
        // fixed upper id, so entries written while this runs (and the purge entry itself) are never touched
        $maxId = DB::table('audit_logs')->where('created_at', '<', $cutoff)->max('id');
        if (! $maxId) {
            AuditLogger::log('audit_purged', null, ['count' => 0, 'cutoff' => $cutoff->toDateTimeString(), 'days' => $days]);

            return ['count' => 0, 'cutoff' => $cutoff->toDateTimeString(), 'file' => null];
        }

        $file = self::ARCHIVE_DIR.'/audit_until_'.$cutoff->format('Y-m-d_His').'.xlsx';
        Excel::store(new AuditArchiveExport($maxId, $cutoff), $file, 'private');

        $count = 0;
        do {
            $n = DB::table('audit_logs')->where('id', '<=', $maxId)->where('created_at', '<', $cutoff)->limit(5000)->delete();
            $count += $n;
        } while ($n > 0);

        AuditLogger::log('audit_purged', null, ['count' => $count, 'cutoff' => $cutoff->toDateTimeString(), 'days' => $days, 'file' => basename($file)]);

        return ['count' => $count, 'cutoff' => $cutoff->toDateTimeString(), 'file' => basename($file)];
    }

    /**
     * Clear the application cache (Cache::flush also empties the file store) and remove stale files: the database cache table,
     * compiled views, and registration uploads kept for abandoned forms.
     *
     * @return array{expired_rows: int, register_files: int}
     */
    public function clearCache(): array
    {
        Cache::flush();
        $expired = 0;
        if (Schema::hasTable('cache')) {
            $expired = DB::table('cache')->where('expiration', '<', now()->getTimestamp())->delete();
            DB::table('cache')->delete();
        }
        if (Schema::hasTable('cache_locks')) {
            DB::table('cache_locks')->where('expiration', '<', now()->getTimestamp())->delete();
        }
        if (! app()->runningUnitTests()) {
            Artisan::call('view:clear');
        }
        $register = app(RegisterUploadStash::class)->purge(24);

        AuditLogger::log('cache_cleared', null, ['expired_rows' => $expired, 'register_files' => $register]);

        return ['expired_rows' => $expired, 'register_files' => $register];
    }

    /** Archive files, newest first: [name, size, time]. */
    public function archives(): array
    {
        $disk = Storage::disk('private');

        return collect($disk->files(self::ARCHIVE_DIR))
            ->filter(fn ($p) => str_ends_with($p, '.xlsx'))
            ->map(fn ($p) => ['name' => basename($p), 'size' => $disk->size($p), 'time' => Carbon::createFromTimestamp($disk->lastModified($p))])
            ->sortByDesc('time')->values()->all();
    }
    /** 25-11-2026: tables whose index statistics are refreshed every month (db:maintain). */
    public const ANALYZE_TABLES = ['inspections', 'inspection_answers', 'inspection_absent_teachers', 'inspection_classroom_observations', 'inspection_classroom_tlm',
        'inspection_evidence', 'inspection_fund_positions', 'inspection_remarks', 'inspection_ratings', 'inspection_schedules', 'inspection_schedule_items',
        'schools', 'users', 'user_jurisdictions', 'registration_requests', 'notices', 'notice_reads', 'holidays', 'daily_school_lists', 'daily_school_list_days',
        'user_login_logs', 'audit_logs'];

    /** Tables that lose many rows (audit:purge, cache:purge, old sign-ins): rebuilt to give the space back. */
    public const OPTIMIZE_TABLES = ['audit_logs', 'user_login_logs', 'cache', 'cache_locks'];

    /**
     * 25-11-2026, database optimisation (db:maintain, monthly after audit:purge / cache:purge):
     * ANALYZE TABLE keeps MySQL's index statistics current, so it keeps choosing the right index as
     * the tables grow; OPTIMIZE TABLE rebuilds the purged tables. MySQL / MariaDB only.
     *
     * @return array{driver: string, analyzed: list<string>, optimized: list<string>, ms: int}
     */
    public function maintainDatabase(): array
    {
        $start = microtime(true);
        $driver = DB::connection()->getDriverName();
        $done = ['driver' => $driver, 'analyzed' => [], 'optimized' => [], 'ms' => 0];
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return $done; // SQLite (tests) has nothing to maintain
        }
        foreach (self::ANALYZE_TABLES as $t) {
            if (Schema::hasTable($t)) {
                DB::select("ANALYZE TABLE `{$t}`");
                $done['analyzed'][] = $t;
            }
        }
        foreach (self::OPTIMIZE_TABLES as $t) {
            if (Schema::hasTable($t)) {
                DB::select("OPTIMIZE TABLE `{$t}`");
                $done['optimized'][] = $t;
            }
        }
        $done['ms'] = (int) round((microtime(true) - $start) * 1000);
        AuditLogger::log('db_maintained', null, ['analyzed' => count($done['analyzed']), 'optimized' => $done['optimized'], 'ms' => $done['ms']]);

        return $done;
    }
}
