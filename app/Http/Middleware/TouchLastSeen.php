<?php

namespace App\Http\Middleware;

use App\Services\LoginMonitorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** V6 Module 6: keeps the signed-in session's last activity time for the login monitoring report. */
class TouchLastSeen
{
    public function __construct(private LoginMonitorService $monitor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $this->monitor->touch($request);

        return $response;
    }
}
