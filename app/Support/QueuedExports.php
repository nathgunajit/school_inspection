<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * 26-11-2026 performance, module B: large downloads are made by the queue worker instead of inside the request.
 *
 * How it works: App\Http\Middleware\QueueLargeExports sees a GET to one of ROUTES, runs the route's own middleware
 * (auth, active, can:… gates, controller middleware, throttle) to check the user may have it, saves the request
 * (URL with its filters, IP, browser) as an ExportJob and answers at once. App\Jobs\GenerateExport replays that
 * request as the same user through the same middleware and controller, and stores the file the controller returns.
 * So every export keeps one code path: its filters, jurisdiction scoping, row caps and audit entry are unchanged.
 */
final class QueuedExports
{
    /** Request attribute set on a replayed request (the middleware lets it through). */
    public const REPLAY = 'si.export_replay';

    /** Routes whose downloads go to the queue => title on the Downloads page. Parameters are added to the title. */
    public const ROUTES = [
        'admin.schools.export' => 'School Master (Excel)',
        'admin.schools.export-pdf' => 'School Master (PDF)',
        'admin.locations.export' => 'Location Master',
        'schools.mine.export' => 'My Schools (Excel)',
        'schools.mine.export-pdf' => 'My Schools (PDF)',
        'reports.coverage.export' => 'Inspection Coverage Report (Excel)',
        'reports.coverage.export-pdf' => 'Inspection Coverage Report (PDF)',
        'reports.ratings.export' => 'Rating Report (Excel)',
        'reports.ratings.export-pdf' => 'Rating Report (PDF)',
        'inspections.export-pdf' => 'Inspections (PDF)',
        'reports.network.export-pdf' => 'Network Issues (PDF)',
        'reports.ranking.export' => 'District Ranking (Excel)', // 27-11-2026
        'reports.ranking.export-pdf' => 'District Ranking (PDF)',
    ];

    public static function enabled(): bool
    {
        return (bool) config('inspection.export_queue.enabled');
    }

    public const HEARTBEAT = 'si:queue-heartbeat';

    /** Called by the queue worker on every loop (AppServiceProvider): "a worker is running". At most one write per 20 s. */
    public static function beat(): void
    {
        $last = (int) \Illuminate\Support\Facades\Cache::get(self::HEARTBEAT, 0);
        if (time() - $last >= 20) {
            \Illuminate\Support\Facades\Cache::put(self::HEARTBEAT, time(), 120);
        }
    }

    /**
     * 27-11-2026: is a queue worker running? On the sync queue the job runs at once, so yes. Otherwise a worker must
     * have beaten in the last 90 s; when none has, downloads are made directly in the request (QueueLargeExports).
     */
    public static function workerAlive(): bool
    {
        if (config('queue.default') === 'sync') {
            return true;
        }

        return time() - (int) \Illuminate\Support\Facades\Cache::get(self::HEARTBEAT, 0) < 90;
    }

    /** A large file made inside a web request: no time limit, the import memory limit. */
    public static function allowLongRequest(): void
    {
        @set_time_limit(0);
        ignore_user_abort(true);
        if ($mem = config('inspection.import_memory')) {
            @ini_set('memory_limit', (string) $mem);
        }
    }

    public static function keepHours(): int
    {
        return max(1, (int) config('inspection.export_queue.keep_hours', 24));
    }

    public static function maxActive(): int
    {
        return max(1, (int) config('inspection.export_queue.max_active', 3));
    }

    public static function handles(?Route $route): bool
    {
        return $route && isset(self::ROUTES[$route->getName()]);
    }

    public static function title(Route $route): string
    {
        $title = self::ROUTES[$route->getName()] ?? 'Download';
        $params = array_filter($route->parameters(), 'is_string');
        if ($route->getName() === 'admin.locations.export') {
            $title = ucfirst($params['type'] ?? '').' Master ('.(($params['format'] ?? '') === 'pdf' ? 'PDF' : 'Excel').')';
        } elseif ($route->getName() === 'inspections.export-pdf') {
            $title = (($params['mode'] ?? '') === 'mine' ? 'My Inspections' : 'Inspections in My Area').' (PDF)';
        }

        return trim($title);
    }

    /**
     * Run the route's middleware (without the "web" group, which already ran or is not wanted in a worker) and then
     * $then. Returns whatever the first refusing middleware returns (403, redirect to sign-in, password change…).
     *
     * @param  bool  $throttle  count the request against the route's throttle (true when queuing, false when replaying)
     */
    public static function throughRouteMiddleware(Request $request, Route $route, bool $throttle, Closure $then): Response
    {
        $router = app(Router::class);
        $names = array_values(array_filter($route->gatherMiddleware(), function ($m) use ($throttle) {
            if (! is_string($m)) {
                return true; // controller closures (e.g. the Rating Report's State Admin check)
            }

            return $m !== 'web' && ($throttle || ! str_starts_with($m, 'throttle'));
        }));
        $middleware = $router->resolveMiddleware($names, $route->excludedMiddleware());

        return Router::toResponse($request, (new Pipeline(app()))->send($request)->through($middleware)
            ->then(fn (Request $r) => $then($r)));
    }

    /**
     * Build the saved GET request for a worker: the same URL, IP and browser, the user signed in for this job only,
     * a throw-away session (flash messages from the controller are read back from it), and the matched route.
     */
    public static function replayRequest(string $url, User $user, ?string $ip, ?string $userAgent): Request
    {
        // In a queue worker the HTTP kernel was never built, so the router does not know the middleware aliases
        // ("auth", "can", "throttle"…) yet; building the kernel registers them.
        app(\Illuminate\Contracts\Http\Kernel::class);

        $request = Request::create($url, 'GET', [], [], [], array_filter([
            'REMOTE_ADDR' => $ip ?: '127.0.0.1',
            'HTTP_USER_AGENT' => $userAgent,
            'HTTP_ACCEPT' => 'text/html',
        ]));
        $request->attributes->set(self::REPLAY, true);
        $session = new Store('export-job', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        app('redirect')->setSession($session); // back()->with('warning') lands here, never in the officer's own session

        Auth::shouldUse('web');
        Auth::guard('web')->setUser($user);
        $request->setUserResolver(fn () => $user);

        $route = app(Router::class)->getRoutes()->match($request);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');
        URL::setRequest($request);

        return $request;
    }

    /** The file name from a Content-Disposition header ("attachment; filename=…"), or null. */
    public static function fileName(Response $response): ?string
    {
        $cd = (string) $response->headers->get('Content-Disposition');
        if (preg_match("/filename\\*=UTF-8''([^;]+)/i", $cd, $m)) {
            return basename(rawurldecode($m[1]));
        }
        if (preg_match('/filename="?([^";]+)"?/i', $cd, $m)) {
            return basename($m[1]);
        }

        return null;
    }
}
