<?php

namespace App\Http\Middleware;

use App\Support\QueuedExports;
use App\Support\SchedulerHeartbeat;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modification V16 (27-11-2026): Today's Schools alerts without the scheduler. When no `schedule:run` has been seen
 * (SchedulerHeartbeat), a signed-in page view runs `today:notify` after the response is sent, at most once every
 * 5 minutes for the whole site. The command sends each alert once (day flags, notified_at / reminded_at), so a
 * scheduler started later never double-sends. Off with TODAY_ALERT_FALLBACK=false (phpunit.xml).
 */
class RunDueAlerts
{
    public const LOCK = 'today-notify:fallback';

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! config('inspection.daily_list.alert_fallback') || ! $request->user() || SchedulerHeartbeat::alive()) {
            return;
        }
        if (! Cache::add(self::LOCK, 1, 300)) {
            return;
        }
        try {
            QueuedExports::allowLongRequest();
            Artisan::call('today:notify');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
