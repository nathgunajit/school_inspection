<?php

use App\Enums\InspectionStatus;
use App\Models\Inspection;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/* A Draft not submitted within N days is cancelled automatically (PRD 8.2). */
Artisan::command('inspections:expire-drafts', function () {
    $days = config('inspection.draft_expiry_days');
    $count = 0;
    Inspection::where('status', InspectionStatus::Draft)
        ->where('started_at', '<', now()->subDays($days))
        ->each(function (Inspection $i) use (&$count, $days) {
            $i->update(['status' => InspectionStatus::Cancelled, 'cancelled_reason' => "Expired: not submitted within {$days} days"]);
            AuditLogger::log('inspection_expired', $i);
            $count++;
        });
    $this->info("Cancelled {$count} expired draft(s).");
})->purpose('Cancel inspection drafts older than the configured expiry');

Schedule::command('inspections:expire-drafts')->dailyAt('00:30');

/* Modification V7: files kept from failed registration attempts are removed after a day. */
Artisan::command('register:purge-tmp {--hours=24 : Remove kept files older than this}', function () {
    $n = app(\App\Services\RegisterUploadStash::class)->purge((int) $this->option('hours'));
    $this->info("Removed {$n} kept registration file(s).");
})->purpose('Delete registration uploads kept for abandoned forms');

Schedule::command('register:purge-tmp')->dailyAt('01:00');

/* 26-11-2026 performance: small thumbnails for photos stored before thumbnails existed. Safe to re-run. */
Artisan::command('evidence:thumbs {--force : Rebuild thumbnails that already exist}', function () {
    $disk = \Illuminate\Support\Facades\Storage::disk(\App\Services\EvidenceStorage::DISK);
    $made = $skipped = $failed = 0;
    \App\Models\InspectionEvidence::query()->where('kind', 'photo')->whereNotNull('storage_path')
        ->select('id', 'storage_path')->chunkById(200, function ($rows) use ($disk, &$made, &$skipped, &$failed) {
            foreach ($rows as $e) {
                if (! $this->option('force') && $disk->exists(\App\Services\EvidenceStorage::thumbPathFor($e->storage_path))) {
                    $skipped++;
                } elseif (\App\Services\EvidenceStorage::makeThumb($e->storage_path)) {
                    $made++;
                } else {
                    $failed++;
                }
            }
        });
    $this->info("Thumbnails: {$made} made, {$skipped} already there, {$failed} could not be made (missing file or no GD).");
})->purpose('Create the small grid thumbnails for stored inspection photos');

/* Modification V13 item 1: keep only the State Administrator and create 3–4 demo officers per level. */
Artisan::command('users:reset-demo {--force : Do not ask for confirmation}', function () {
    if (app()->isProduction()) {
        $this->error('Refused: demo users cannot be reset in production.');

        return 1;
    }
    if (! $this->option('force') && ! $this->confirm('Delete every user except the State Administrator (with their inspections and schedules) and create demo officers?')) {
        return 1;
    }
    $r = app(\App\Services\DemoUserReset::class)->run();
    $this->info("Removed {$r['removed']} user(s) and {$r['inspections']} inspection(s); {$r['created']} demo officer(s) created (password ".\Database\Seeders\DemoUserSeeder::PASSWORD.').');

    return 0;
})->purpose('Remove all users except the State Administrator and seed demo officers');

/* 25-11-2026: clean test data by group (inspections, schedules, login log, audit log). Local server only. */
$cleanTestData = function (string $group, bool $force, $cmd): int {
    $cleaner = app(\App\Services\TestDataCleaner::class);
    $groups = array_keys(\App\Services\TestDataCleaner::GROUPS);
    if (! \App\Services\TestDataCleaner::enabled()) {
        $cmd->error('Clean Test Data is disabled on this server (never in production; TEST_DATA_CLEANUP).');

        return 1;
    }
    if ($group !== 'all' && ! in_array($group, $groups, true)) {
        $cmd->error('Unknown group. Use one of: '.implode(', ', $groups).', all.');

        return 1;
    }
    $pick = $group === 'all' ? $groups : [$group];
    $preview = $cleaner->preview();
    foreach ($pick as $key) {
        $p = $preview[$key];
        $cmd->line($p['title'].($p['pre_sql'] ? ' (first: '.$p['pre_sql'].')' : ''));
        $cmd->table(['No.', 'Table', 'Rows', 'SQL'], array_map(fn ($r, $i) => [$i + 1, $r['table'], $r['rows'], $r['sql']], $p['rows'], array_keys($p['rows'])));
        $cmd->line("Total {$p['total']} row(s), {$p['files']} file(s).");
    }
    if (! $force && ! $cmd->confirm('Delete all of this? It cannot be undone.')) {
        return 1;
    }
    foreach ($pick as $key) {
        $r = $cleaner->run($key);
        $cmd->info("Cleaned {$r['title']}: {$r['total']} row(s) and {$r['files']} file(s) removed".($r['archive'] ? " (archive: {$r['archive']})" : '').'.');
    }

    return 0;
};

Artisan::command('data:clean-test {group : inspections, schedules, login_logs, audit_logs or all} {--force : Do not ask for confirmation}', function () use ($cleanTestData) {
    return $cleanTestData((string) $this->argument('group'), (bool) $this->option('force'), $this);
})->purpose('Clean test data by group (local server only; never in production)');

Artisan::command('inspections:clean-test-data {--force : Do not ask for confirmation}', function () use ($cleanTestData) {
    return $cleanTestData('inspections', (bool) $this->option('force'), $this);
})->purpose('Same as data:clean-test inspections');

/* Modification V13 item 4: audit entries older than the retention period go to an Excel archive, then are deleted. */
Artisan::command('audit:purge {--days= : Keep this many days (default AUDIT_RETENTION_DAYS)}', function () {
    $days = $this->option('days') !== null ? (int) $this->option('days') : null;
    $r = app(\App\Services\HousekeepingService::class)->purgeAudit($days);
    $this->info("Removed {$r['count']} audit entr".($r['count'] === 1 ? 'y' : 'ies')." older than {$r['cutoff']}".($r['file'] ? " (archive: {$r['file']})" : '').'.');
})->purpose('Archive and delete audit log entries older than the retention period');

Artisan::command('cache:purge', function () {
    $r = app(\App\Services\HousekeepingService::class)->clearCache();
    $this->info("Cache cleared ({$r['expired_rows']} expired cache row(s), {$r['register_files']} kept registration file(s) removed).");
})->purpose('Clear the application cache and stale cache files');

/* 25-11-2026, database optimisation: index statistics refreshed, purged tables rebuilt (MySQL / MariaDB). */
Artisan::command('db:maintain', function () {
    $r = app(\App\Services\HousekeepingService::class)->maintainDatabase();
    if (! $r['analyzed']) {
        $this->info("Nothing to do on {$r['driver']} (MySQL / MariaDB only).");

        return 0;
    }
    $this->info('Analyzed '.count($r['analyzed']).' table(s), optimized '.implode(', ', $r['optimized'])." in {$r['ms']} ms.");

    return 0;
})->purpose('Refresh MySQL index statistics and rebuild purged tables');
Schedule::command('audit:purge')->monthlyOn(1, '02:00');
Schedule::command('cache:purge')->monthlyOn(1, '02:15');
Schedule::command('db:maintain')->monthlyOn(1, '02:30'); // 25-11-2026, after the purges

/* Location IDs 1…n in Location Master code order (district 1801 → 1); every link moves with them. */
Artisan::command('locations:renumber {--force : Do not ask for confirmation}', function () {
    if (! $this->option('force') && ! $this->confirm('Renumber districts, blocks and clusters 1…n in code order and update every school, officer and inspection that points to them?')) {
        return 1;
    }
    $r = app(\App\Services\LocationRenumber::class)->run();
    $this->info("Renumbered {$r['districts']} district(s), {$r['blocks']} block(s) and {$r['clusters']} cluster(s) (0 = already in order).");

    return 0;
})->purpose('Renumber location IDs from 1 in code order');
