<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Users with a temporary password must set a new one before continuing. */
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->must_change_password && ! $request->routeIs('password.change*', 'logout')) {
            return redirect()->route('password.change')->with('warning', 'Please set a new password to continue.');
        }

        return $next($request);
    }
}
