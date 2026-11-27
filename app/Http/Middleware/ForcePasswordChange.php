<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Users with a temporary password are asked to set a new one.
 * Since 27-11-2026 this is the skippable "Change your password?" popup (session flag pw_prompt,
 * partial partials/password-prompt), not a forced redirect.
 */
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->must_change_password && $request->hasSession() && ! $request->session()->has('pw_prompt')) {
            $request->session()->put('pw_prompt', true);
        }

        return $next($request);
    }
}
