<?php

namespace App\Http\Middleware;

use App\Support\PageUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 25-11-2026, one user per browser: a save sent from a page that belonged to another user
 * (the browser's session now belongs to someone else) is refused, so nothing is written under
 * the wrong name. Requests without the page token pass (tests, older cached pages).
 */
class EnsurePageUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $request->isMethodSafe() || $request->routeIs('logout', 'login.switch')) { // signing out always works
            return $next($request);
        }
        $sent = $request->header(PageUser::HEADER) ?: $request->input(PageUser::FIELD);
        $request->request->remove(PageUser::FIELD); // never reaches the controllers
        if (! is_string($sent) || $sent === '' || hash_equals(PageUser::token($user), $sent)) {
            return $next($request);
        }

        $message = 'Another user ('.$user->name.') is signed in on this browser, so your last action was not saved. Only one user can be signed in at a time in one browser.';
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => $message, 'user_changed' => true], 409);
        }

        return redirect()->route('dashboard')->with('warning', $message);
    }
}
