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
