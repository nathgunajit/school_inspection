<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Logs out users who were deactivated or locked while signed in (PRD 6.2). */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && ! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['mobile' => 'Your account is not active. Contact your administrator.']);
        }

        return $next($request);
    }
}
