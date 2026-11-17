<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Mobile + password login with CAPTCHA after 3 failures and a 15 minute lock after 5 (PRD 6.3).
 */
class LoginController extends Controller
{
    public function show(Request $request)
    {
        return view('auth.login', ['needsCaptcha' => $this->needsCaptcha($request)]);
    }

    public function login(Request $request)
    {
        $cfg = config('inspection.login');
        $rules = [
            'mobile' => ['required', 'digits:10'],
            'password' => ['required', 'string'],
        ];
        if ($this->needsCaptcha($request) && config('inspection.captcha_enabled')) {
            $rules['captcha'] = ['required', 'captcha'];
        }
        $data = $request->validate($rules, ['captcha.captcha' => 'The CAPTCHA code is incorrect.']);

        $user = User::where('mobile', $data['mobile'])->first();

        // Expired temporary lock -> unlock
        if ($user && $user->status === UserStatus::Locked && $user->locked_until && $user->locked_until->isPast()) {
            $user->update(['status' => UserStatus::Active, 'failed_login_attempts' => 0, 'locked_until' => null]);
        }

        if ($user && $user->status === UserStatus::Locked) {
            $mins = max(1, (int) ceil(now()->diffInSeconds($user->locked_until, false) / 60));
            throw ValidationException::withMessages(['mobile' => "Account locked after repeated failed attempts. Try again in {$mins} minute(s)."]);
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $this->registerFailure($request, $user, $cfg);
            throw ValidationException::withMessages(['mobile' => 'The mobile number or password is incorrect.']);
        }

        $message = match ($user->status) {
            UserStatus::Pending => 'Your registration is pending approval. You will be able to sign in once it is approved.',
            UserStatus::Rejected => 'Your registration was rejected: '.($user->registrationRequests()->first()?->reason ?? 'no reason given').'. You may correct your details and register again.',
            UserStatus::Inactive => 'Your account has been deactivated. Contact your administrator.',
            default => null,
        };
        if ($message) {
            throw ValidationException::withMessages(['mobile' => $message]);
        }

        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();
        $request->session()->forget('login_failures');

        Auth::login($user);
        $request->session()->regenerate();
        AuditLogger::log('login', $user, [], $user);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
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

        AuditLogger::log('login_failed', $user, ['mobile' => $request->input('mobile')], $user);
    }
}
