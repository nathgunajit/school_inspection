<?php

namespace App\Support;

use App\Jobs\RecheckAheadLists;
use Illuminate\Support\Carbon;

/**
 * 28-11-2026: called from the Holiday / HolidayWithdrawal model events. A change to a day from tomorrow up to the
 * Today's Schools look-ahead (8 days) rechecks the lists made ahead, once per request and area, when the request ends
 * (on the queue when a worker runs). The pending areas live on the app container, never in statics.
 */
final class AheadListRecheck
{
    private const KEY = 'si.ahead-list-recheck';

    public static function touch(?string $date, ?int $districtId): void
    {
        if ($date === null || ! config('inspection.daily_list.holiday_recheck', true)) {
            return;
        }
        $d = Carbon::parse($date)->startOfDay();
        if ($d->lte(today()) || $d->gt(today()->addDays(8))) {
            return; // only a list made ahead (at most a week) can be affected
        }
        $app = app();
        if (! $app->bound(self::KEY)) {
            $app->instance(self::KEY, new \ArrayObject());
            $app->terminating(fn () => self::flush());
        }
        $app->make(self::KEY)[$districtId === null ? 'state' : (string) $districtId] = $districtId;
    }

    /** Run the rechecks collected so far (a state change covers every district). */
    public static function flush(): void
    {
        $app = app();
        if (! $app->bound(self::KEY)) {
            return;
        }
        $pending = $app->make(self::KEY)->getArrayCopy();
        $app->forgetInstance(self::KEY);
        $app->offsetUnset(self::KEY);
        $areas = array_key_exists('state', $pending) ? [null] : array_values($pending);
        foreach ($areas as $districtId) {
            QueuedExports::workerAlive() ? RecheckAheadLists::dispatch($districtId) : RecheckAheadLists::dispatchSync($districtId);
        }
    }
}
