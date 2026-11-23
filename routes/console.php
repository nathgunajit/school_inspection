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

Schedule::command('audit:purge')->monthlyOn(1, '02:00');
Schedule::command('cache:purge')->monthlyOn(1, '02:15');

/* Location IDs 1…n in Location Master code order (district 1801 → 1); every link moves with them. */
Artisan::command('locations:renumber {--force : Do not ask for confirmation}', function () {
    if (! $this->option('force') && ! $this->confirm('Renumber districts, blocks and clusters 1…n in code order and update every school, officer and inspection that points to them?')) {
        return 1;
    }
    $r = app(\App\Services\LocationRenumber::class)->run();
    $this->info("Renumbered {$r['districts']} district(s), {$r['blocks']} block(s) and {$r['clusters']} cluster(s) (0 = already in order).");

    return 0;
})->purpose('Renumber location IDs from 1 in code order');
