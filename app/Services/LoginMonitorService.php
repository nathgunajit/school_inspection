<?php

namespace App\Services;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserLoginLog;
use App\Support\UserAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * V6 Module 6: records sign-ins, refused attempts, activity and sign-outs for the login monitoring report.
 * Monitoring must never block a sign-in, so every write is wrapped and failures are only reported.
 */
class LoginMonitorService
{
    public const SESSION_KEY = 'login_log_id';

    private const TOUCH_KEY = 'login_log_touched';

    private const TOUCH_EVERY_SECONDS = 60;

    /** A successful sign-in. Call after the session is regenerated. */
    public function success(Request $request, User $user): void
    {
        $this->safely(function () use ($request, $user) {
            $log = UserLoginLog::create($this->base($request) + [
                'user_id' => $user->id, 'mobile' => $user->mobile, 'status' => 'success',
                'session_id' => $request->session()->getId(), 'last_seen_at' => now(),
            ]);
            $request->session()->put(self::SESSION_KEY, $log->id);
            $request->session()->put(self::TOUCH_KEY, now()->timestamp);
        });
    }

    /** A refused attempt: failed (wrong password or unknown number), locked, or denied (pending, rejected, inactive). */
    public function refused(Request $request, ?User $user, string $status, ?string $reason = null): void
    {
        $this->safely(fn () => UserLoginLog::create($this->base($request) + [
            'user_id' => $user?->id, 'mobile' => mb_substr((string) $request->input('mobile'), 0, 15) ?: null,
            'status' => $status, 'reason' => $reason,
        ]));
    }

    /** Updates last_seen_at at most once a minute per session. */
    public function touch(Request $request): void
    {
        if (! $request->hasSession() || ! ($id = $request->session()->get(self::SESSION_KEY))) {
            return;
        }
        $last = (int) $request->session()->get(self::TOUCH_KEY, 0);
        if (now()->timestamp - $last < self::TOUCH_EVERY_SECONDS) {
            return;
        }
        $request->session()->put(self::TOUCH_KEY, now()->timestamp);
        $this->safely(fn () => UserLoginLog::whereKey($id)->whereNull('logout_at')->update(['last_seen_at' => now()]));
    }

    /** Sign-out. Call before the session is invalidated. */
    public function logout(Request $request, string $reason = 'manual'): void
    {
        if (! ($id = $request->session()->get(self::SESSION_KEY))) {
            return;
        }
        $this->safely(fn () => UserLoginLog::whereKey($id)->whereNull('logout_at')
            ->update(['logout_at' => now(), 'last_seen_at' => now(), 'logout_reason' => $reason]));
    }

    /* ---------------- report (State Administrator) ---------------- */

    /** Validated filters with defaults: today, all levels, districts and statuses. */
    public function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'level' => ['nullable', 'in:'.implode(',', array_column(UserLevel::cases(), 'value'))],
            'district_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:'.implode(',', [...array_keys(UserLoginLog::STATUSES), 'online'])],
            'q' => ['nullable', 'string', 'max:60'],
        ]);

        return [
            'from' => $request->date('from')?->toDateString() ?? today()->toDateString(),
            'to' => $request->date('to')?->toDateString() ?? today()->toDateString(),
            'level' => $data['level'] ?? null,
            'district_id' => isset($data['district_id']) ? (int) $data['district_id'] : null,
            'status' => $data['status'] ?? null,
            'q' => trim((string) ($data['q'] ?? '')) ?: null,
        ];
    }

    /** Attempts in the period, filtered; newest first. */
    public function query(array $f): Builder
    {
        return UserLoginLog::query()
            ->with(['user' => fn ($q) => $q->select('id', 'name', 'mobile', 'level', 'status', 'photo_path', 'designation_id', 'employee_id')
                ->with(['designation:id,code,label', 'currentJurisdiction.district:id,name', 'currentJurisdiction.block:id,name', 'currentJurisdiction.cluster:id,name', 'currentJurisdiction.state:id,name'])])
            ->whereBetween('user_login_logs.login_at', [Carbon::parse($f['from'])->startOfDay(), Carbon::parse($f['to'])->endOfDay()])
            ->when($f['level'], fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->withTrashed()->where('level', $v)))
            ->when($f['district_id'], fn ($q, $v) => $q->whereHas('user.currentJurisdiction', fn ($j) => $j->where('district_id', $v)))
            ->when($f['status'] === 'online', fn ($q) => $this->onlineScope($q))
            ->when($f['status'] && $f['status'] !== 'online', fn ($q) => $q->where('user_login_logs.status', $f['status']))
            ->when($f['q'], fn ($q, $s) => $q->where(fn ($w) => $w->where('user_login_logs.mobile', 'like', "%$s%")
                ->orWhereHas('user', fn ($u) => $u->withTrashed()->where('name', 'like', "%$s%")->orWhere('employee_id', 'like', "%$s%"))))
            ->latest('user_login_logs.login_at')->latest('user_login_logs.id');
    }

    /** Summary tiles and breakdowns for the period. */
    public function summary(array $f): array
    {
        $period = $this->query(array_merge($f, ['status' => null]))->reorder();
        $success = (clone $period)->where('user_login_logs.status', 'success');
        $refused = (clone $period)->whereIn('user_login_logs.status', ['failed', 'locked', 'denied']);

        $officers = User::query()->where('status', UserStatus::Active)->where('level', '!=', UserLevel::StateAdmin->value)
            ->when($f['level'], fn ($q, $v) => $q->where('level', $v))
            ->when($f['district_id'], fn ($q, $v) => $q->whereHas('currentJurisdiction', fn ($j) => $j->where('district_id', $v)));

        $signedInByLevel = (clone $success)->join('users', 'users.id', '=', 'user_login_logs.user_id')
            ->selectRaw('users.level, count(distinct user_login_logs.user_id) as n')->groupBy('users.level')->pluck('n', 'level');
        $activeByLevel = (clone $officers)->selectRaw('level, count(*) as n')->groupBy('level')->pluck('n', 'level');
        $byLevel = collect([UserLevel::State, UserLevel::District, UserLevel::Block, UserLevel::Cluster])
            ->filter(fn ($l) => ! $f['level'] || $f['level'] === $l->value)
            ->map(fn ($l) => ['level' => $l->label(), 'active' => (int) ($activeByLevel[$l->value] ?? 0), 'signed_in' => (int) ($signedInByLevel[$l->value] ?? 0)])
            ->values()->all();

        return [
            'logins' => (clone $success)->count(),
            'users' => (clone $success)->distinct()->count('user_id'),
            'refused' => (clone $refused)->count(),
            'online' => $this->onlineScope(UserLoginLog::query())->distinct()->count('user_id'),
            'locked' => User::where('status', UserStatus::Locked)->where('locked_until', '>', now())->count(),
            'never' => (clone $officers)->whereNull('last_login_at')->count(),
            'by_level' => $byLevel,
            // numbers with most refused attempts: a sign of guessing
            'top_refused' => (clone $refused)->selectRaw('mobile, count(*) as attempts, count(distinct ip_address) as ips, max(login_at) as last_at')
                ->whereNotNull('mobile')->groupBy('mobile')->orderByDesc('attempts')->limit(5)->get(),
        ];
    }

    /** Successful sign-ins still open: not signed out and active within the session lifetime. */
    public function onlineScope(Builder $q): Builder
    {
        $since = now()->subMinutes((int) config('session.lifetime'));

        return $q->where('user_login_logs.status', 'success')->whereNull('user_login_logs.logout_at')
            ->where(fn ($w) => $w->where('last_seen_at', '>=', $since)->orWhere(fn ($x) => $x->whereNull('last_seen_at')->where('login_at', '>=', $since)));
    }

    /** Session state labels for the report. */
    public static function stateLabel(?string $state): array
    {
        return match ($state) {
            'online' => ['Online', 'success'],
            'signed_out' => ['Signed out', 'secondary'],
            'timed_out' => ['Timed out', 'warning'],
            default => ['—', 'secondary'],
        };
    }

    private function base(Request $request): array
    {
        $ua = mb_substr((string) $request->userAgent(), 0, 255) ?: null;

        return ['ip_address' => $request->ip(), 'user_agent' => $ua, 'login_at' => now()] + UserAgent::parse($ua);
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
