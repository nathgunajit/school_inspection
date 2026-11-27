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
        $this->app->singleton(\App\Services\PasswordCrypto::class);
        $this->app->bind(OtpSender::class, LogOtpSender::class);
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // 27-11-2026: a running queue worker leaves a heartbeat, so downloads know whether to queue (QueuedExports::workerAlive())
        Event::listen([\Illuminate\Queue\Events\Looping::class, \Illuminate\Queue\Events\JobProcessed::class], fn () => \App\Support\QueuedExports::beat());

        // V15: a new in-app alert makes the bell's cached unread count stale
        Event::listen(\Illuminate\Notifications\Events\NotificationSent::class, function ($e) {
            if ($e->channel === 'database' && $e->notifiable instanceof User) {
                \App\Services\AlertService::forget($e->notifiable->id);
            }
        });

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
        // Inspection Coverage Report settings: the State Admin (state default) or a District officer with Manage users (own district)
        Gate::define('configure_coverage', fn (User $user) => $user->isActive() && ($user->isStateAdmin()
            || ($user->level === \App\Enums\UserLevel::District && app(PermissionService::class)->has($user, Permission::MANAGE_USERS))));
        // V14 (25-11-2026): holiday calendar and Today's schools settings, the same rule (a district manages its own rows only)
        Gate::define('manage_holidays', fn (User $user) => Gate::forUser($user)->allows('configure_coverage'));
        // V14 item 1: Instruction / Notification / Order letters, sent by the same people
        Gate::define('issue_notices', fn (User $user) => Gate::forUser($user)->allows('configure_coverage'));

        RateLimiter::for('login', fn (Request $r) => config('inspection.loadtest') && ! app()->isProduction()
            ? Limit::none() // load test from one machine (Product Details/LOAD_TEST.md); never in production
            : Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('register', fn (Request $r) => Limit::perHour(10)->by($r->ip()));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));

        // 25-11-2026, database optimisation: slow queries are logged, so missing indexes show up on the server
        if (($slowMs = (int) config('inspection.slow_query_ms')) > 0) {
            \Illuminate\Support\Facades\DB::listen(function (\Illuminate\Database\Events\QueryExecuted $q) use ($slowMs) {
                if ($q->time >= $slowMs) {
                    \Illuminate\Support\Facades\Log::warning('Slow query ('.round($q->time).' ms)', ['sql' => $q->sql, 'url' => app()->runningInConsole() ? 'console' : request()->path()]);
                }
            });
        }

        // 26-11-2026: catch N+1 queries early. Outside production a lazy-loaded relation is logged (or thrown with
        // LAZY_LOADING_CHECK=throw); users never see an error from it.
        $lazy = config('inspection.lazy_loading');
        if (! $this->app->isProduction() && in_array($lazy, ['log', 'throw'], true)) {
            \Illuminate\Database\Eloquent\Model::preventLazyLoading();
            if ($lazy === 'log') {
                \Illuminate\Database\Eloquent\Model::handleLazyLoadingViolationUsing(function ($model, string $relation) {
                    \Illuminate\Support\Facades\Log::warning('Lazy loading ['.$relation.'] on ['.$model::class.']', [
                        'url' => app()->runningInConsole() ? 'console' : request()->path(),
                    ]);
                });
            }
        }

        Event::listen(Logout::class, function (Logout $e) {
            if ($e->user) {
                AuditLogger::log('logout', $e->user, [], $e->user);
            }
        });
    }
}
