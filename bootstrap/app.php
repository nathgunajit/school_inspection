<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'password.fresh' => \App\Http\Middleware\ForcePasswordChange::class,
        ]);
        // 26-11-2026: download buttons stay busy until the file arrives (cookie read by resources/js/download-busy.js)
        $middleware->web(prepend: [\App\Http\Middleware\MarkDownloadDone::class]);
        $middleware->encryptCookies(except: [\App\Http\Middleware\MarkDownloadDone::COOKIE]);
        // V6 Module 5: password fields arrive RSA-encrypted from the browser
        $middleware->web(append: [\App\Http\Middleware\DecryptPasswordFields::class, \App\Http\Middleware\TouchLastSeen::class,
            \App\Http\Middleware\EnsurePageUser::class, \App\Http\Middleware\CompactHtml::class, // 25-11-2026: one user per browser; smaller HTML
            \App\Http\Middleware\QueueLargeExports::class]); // 26-11-2026: large downloads are made by the queue (Downloads page)
        // V12 item 8: named routes, so the redirects also work when the app runs in a sub-folder (e.g. XAMPP /SCHOOLINSPECTION/public)
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // V12 item 8: a form sent after the session expired (419) goes back to sign-in, not to an error page
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null; // the wizard handles 419 itself
            }
            if ($request->routeIs('logout')) {
                return redirect()->route('login')->with('status', 'You have been signed out.');
            }
            if (! $request->user()) { // e.g. the sign-in or registration page was left open too long
                return redirect()->to(url()->previous(route('login')))->withInput($request->except(['password', 'password_confirmation', 'current_password', '_token']))
                    ->with('warning', 'The page had expired. Please enter your details again.');
            }

            return back()->withInput($request->except(['password', 'password_confirmation', 'current_password', '_token']))
                ->with('warning', 'The page had expired, so it was not saved. Please check and send it again.');
        });
    })->create();
