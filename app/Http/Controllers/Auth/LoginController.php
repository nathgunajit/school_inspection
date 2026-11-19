<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LoginMonitorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Mobile + password login (PRD 6.3, Modification V3): CAPTCHA after 2 failures and the account is
 * locked for 20 minutes after 3 failures (values in config('inspection.login')).
 */
class LoginController extends Controller
{
    public function __construct(private LoginMonitorService $monitor) {}

    public function show(Request $request)
    {
        if ($request->boolean('expired')) {
            $request->session()->now('warning', 'You were signed out after '.config('session.lifetime').' minutes without activity. Please sign in again.');
        }

        return view('auth.login', ['needsCaptcha' => $this->needsCaptcha($request), 'demoUsers' => $this->demoUsers()]);
    }

    /**
     * Active demo officers from DemoUserSeeder (mobile 90000000xx), for the login page helper.
     * Empty in production or when DEMO_LOGINS=false.
     */
    private function demoUsers()
    {
        if (app()->isProduction() || ! config('inspection.demo_logins')) {
            return collect();
        }

        return User::with('designation')
            ->where('mobile', 'like', '90000000%')
            ->where('status', UserStatus::Active)
            ->orderBy('mobile')
            ->get(['id', 'name', 'mobile', 'level', 'designation_id'])
            ->groupBy(fn ($u) => $u->level->label());
    }

    public function login(Request $request)
    {
        $cfg = config('inspection.login');
        $rules = [
            'mobile' => ['required', 'digits:10', 'regex:/^[6-9]\d{9}$/'],
            'password' => ['required', 'string'],
        ];
        if ($this->needsCaptcha($request) && config('inspection.captcha_enabled')) {
            $rules['captcha'] = ['required', 'captcha'];
        }
        $data = $request->validate($rules, ['captcha.captcha' => 'The CAPTCHA code is incorrect.', 'mobile.regex' => 'Mobile number must be 10 digits starting with 6, 7, 8 or 9.']);

        $user = User::where('mobile', $data['mobile'])->first();

        // Expired temporary lock -> unlock
        if ($user && $user->status === UserStatus::Locked && $user->locked_until && $user->locked_until->isPast()) {
            $user->update(['status' => UserStatus::Active, 'failed_login_attempts' => 0, 'locked_until' => null]);
        }

        if ($user && $user->status === UserStatus::Locked) {
            $this->monitor->refused($request, $user, 'locked', 'Account locked');
            $mins = max(1, (int) ceil(now()->diffInSeconds($user->locked_until, false) / 60));
            throw ValidationException::withMessages(['mobile' => "Account locked after repeated failed attempts. Try again in {$mins} minute(s)."]);
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $this->registerFailure($request, $user, $cfg);
            // Same wording whether or not the mobile exists (no account enumeration)
            throw ValidationException::withMessages(['mobile' => 'The mobile number or password is incorrect. After '
                .$cfg['lock_after_failures'].' wrong attempts the account is locked for '.$cfg['lock_minutes'].' minutes.']);
        }

        $message = match ($user->status) {
            UserStatus::Pending => 'Your registration is pending approval. You will be able to sign in once it is approved.',
            UserStatus::Rejected => 'Your registration was rejected: '.($user->registrationRequests()->first()?->reason ?? 'no reason given').'. You may correct your details and register again.',
            UserStatus::Inactive => 'Your account has been deactivated. Contact your administrator.',
            default => null,
        };
        if ($message) {
            $this->monitor->refused($request, $user, 'denied', 'Account '.$user->status->value);
            throw ValidationException::withMessages(['mobile' => $message]);
        }

        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();
        $request->session()->forget('login_failures');

        Auth::login($user);
        $request->session()->regenerate();
        AuditLogger::log('login', $user, [], $user);
        $this->monitor->success($request, $user);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        $this->monitor->logout($request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }

    private function needsCaptcha(Request $request): bool
    {
        return (int) $request->session()->get('login_failures', 0) >= config('inspection.login.captcha_after_failures');
    }

    private function registerFailure(Request $request, ?User $user, array $cfg): void
    {
        $request->session()->put('login_failures', (int) $request->session()->get('login_failures', 0) + 1);

        if ($user) {
            $attempts = $user->failed_login_attempts + 1;
            $update = ['failed_login_attempts' => $attempts];
            if ($attempts >= $cfg['lock_after_failures'] && $user->status === UserStatus::Active) {
                $update += ['status' => UserStatus::Locked, 'locked_until' => now()->addMinutes($cfg['lock_minutes'])];
            }
            $user->forceFill($update)->save();
        }
        $locked = isset($update['locked_until']);
        $this->monitor->refused($request, $user, $locked ? 'locked' : 'failed', $locked ? 'Locked after '.$cfg['lock_after_failures'].' failures' : ($user ? 'Wrong password' : 'Unknown mobile number'));

        AuditLogger::log('login_failed', $user, ['mobile' => $request->input('mobile')], $user);
    }
}
