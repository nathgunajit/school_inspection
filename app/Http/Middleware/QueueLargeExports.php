<?php

namespace App\Http\Middleware;

use App\Jobs\GenerateExport;
use App\Models\ExportJob;
use App\Support\QueuedExports;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 26-11-2026 performance, module B (web group): a large download (QueuedExports::ROUTES) is queued instead of being
 * made inside the request. The route's own middleware runs first, so only a download the user may have is queued;
 * anything it refuses (403, sign-in, password change, throttle) is answered as before.
 */
class QueueLargeExports
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if (! QueuedExports::enabled() || ! $request->isMethod('GET') || $request->attributes->get(QueuedExports::REPLAY)
            || ! QueuedExports::handles($route) || ! $request->user()) {
            return $next($request);
        }
        if (! QueuedExports::workerAlive()) {
            // 27-11-2026: no queue worker is running, so a queued job would wait for ever: make the file here, as before
            QueuedExports::allowLongRequest();

            return $next($request);
        }

        $allowed = new Response('', 204);
        $check = QueuedExports::throughRouteMiddleware($request, $route, true, fn () => $allowed);
        if ($check !== $allowed) {
            return $check; // refused exactly as the normal request would be
        }

        $user = $request->user();
        $title = QueuedExports::title($route);
        if (ExportJob::where('user_id', $user->id)->whereIn('status', ExportJob::ACTIVE)->count() >= QueuedExports::maxActive()) {
            return $this->answer($request, null, 'You already have '.QueuedExports::maxActive().' downloads being prepared. Please wait for one to finish (Downloads page).', 'warning');
        }

        $job = ExportJob::create([
            'user_id' => $user->id, 'route_name' => $route->getName(), 'url' => $request->fullUrl(), 'title' => $title,
            'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
        ]);
        try {
            GenerateExport::dispatch($job->id);
        } catch (\Throwable $e) { // the sync queue runs the job here and rethrows its error
            report($e);
        }
        $job->refresh();

        return $this->answer($request, $job, $job->status === 'failed'
            ? $title.' could not be made: '.$job->error
            : $title.' is being prepared. It downloads by itself when ready, and stays on the Downloads page for '.QueuedExports::keepHours().' hours.',
            $job->status === 'failed' ? 'warning' : 'info');
    }

    private function answer(Request $request, ?ExportJob $job, string $message, string $level): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'job' => $job?->toClientArray()], $job ? 202 : 429);
        }
        $fallback = route('downloads.index');
        $back = url()->previous($fallback);
        // never send the officer "back" to the download link itself (opened in a new tab or typed)
        $target = $back === $request->fullUrl() ? $fallback : $back;

        return redirect()->to($target)->with($level, $message)->with('export_job', $job?->getRouteKey());
    }
}
