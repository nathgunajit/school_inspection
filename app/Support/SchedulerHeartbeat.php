<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Modification V16 (27-11-2026): is the Laravel scheduler (`schedule:run` every minute) running? Each scheduled task
 * that starts leaves a beat (AppServiceProvider). When none is seen, the Today's Schools alerts (`today:notify`) are run
 * from page views instead (App\Http\Middleware\RunDueAlerts), so they are still sent on a plain XAMPP install.
 */
class SchedulerHeartbeat
{
    public const KEY = 'si:scheduler-heartbeat';

    /** today:notify runs every 5 minutes, so a running scheduler always beats well inside this window. */
    public const ALIVE_SECONDS = 600;

    public static function beat(): void
    {
        Cache::put(self::KEY, time(), 900);
    }

    public static function alive(): bool
    {
        return time() - (int) Cache::get(self::KEY, 0) < self::ALIVE_SECONDS;
    }

    public static function lastSeen(): ?int
    {
        $t = (int) Cache::get(self::KEY, 0);

        return $t ?: null;
    }
}
