<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\JurisdictionService;
use App\Services\Otp\LogOtpSender;
use App\Services\Otp\OtpSender;
use App\Services\PermissionService;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PermissionService::class);
        $this->app->scoped(JurisdictionService::class);
        $this->app->bind(OtpSender::class, LogOtpSender::class);
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // PRD 14: HTTPS only in production
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Each permission slug becomes a gate: @can('manage_masters'), ->middleware('can:manage_masters')
        $slugs = [
            Permission::APPROVE_REGISTRATIONS, Permission::MANAGE_USERS, Permission::MANAGE_MASTERS,
            Permission::CONDUCT_INSPECTIONS, Permission::VIEW_REPORTS, Permission::EXPORT_EXCEL, Permission::UNLOCK_INSPECTIONS,
        ];
        foreach ($slugs as $slug) {
            Gate::define($slug, fn (User $user) => app(PermissionService::class)->has($user, $slug));
        }
        Gate::define('state_admin', fn (User $user) => $user->isStateAdmin() && $user->isActive());

        RateLimiter::for('login', fn (Request $r) => config('inspection.loadtest') && ! app()->isProduction()
            ? Limit::none() // load test from one machine (LOAD_TEST.md); never in production
            : Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('register', fn (Request $r) => Limit::perHour(10)->by($r->ip()));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));

        Event::listen(Logout::class, function (Logout $e) {
            if ($e->user) {
                AuditLogger::log('logout', $e->user, [], $e->user);
            }
        });
    }
}
