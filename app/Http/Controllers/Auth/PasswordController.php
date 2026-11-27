<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\MathCaptcha;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Change password, plus self-service reset: with the mobile number and date of birth (27-11-2026), or by email link.
 * Phase 1 has no SMS OTP; officers with neither ask the State Admin to reset.
 */
class PasswordController extends Controller
{
    public function edit()
    {
        return view('auth.change-password');
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'max:64', 'different:current_password', PasswordRule::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $user->forceFill(['password' => $request->password, 'must_change_password' => false])->save();
        $request->session()->forget('pw_prompt');
        AuditLogger::log('password_changed', $user);

        return redirect()->route('dashboard')->with('success', 'Your password has been changed.');
    }

    /** 27-11-2026: "Skip for now" on the first-sign-in popup; it is not asked again. */
    public function skip(Request $request)
    {
        $user = $request->user();
        $temporary = (bool) $user->must_change_password;
        if ($temporary) {
            $user->forceFill(['must_change_password' => false])->save();
        }
        $request->session()->forget('pw_prompt');
        AuditLogger::log('password_change_skipped', $user, ['temporary' => $temporary]);

        $back = url()->previous();

        return redirect()->to(str_contains($back, '/password/change') ? route('dashboard') : $back)
            ->with('info', 'You can change your password any time from My Profile.');
    }

    public function forgot()
    {
        if (MathCaptcha::enabled()) {
            app(MathCaptcha::class)->refresh(); // a new sum on every visit
        }

        return view('auth.forgot-password', ['mathCaptcha' => MathCaptcha::enabled()]);
    }

    /** Failed Mobile + DOB attempts allowed per mobile number, and the wait after them (seconds). */
    private const DOB_ATTEMPTS = 5;

    private const DOB_DECAY = 1800;

    /**
     * 27-11-2026: self-service reset with the registered mobile number and date of birth (no email needed).
     * Guarded by the math CAPTCHA, a per-mobile attempt limit and one message for every failure, so it never tells
     * whether a number is registered. Only Active (or Locked, which it unlocks) accounts with a date of birth qualify.
     */
    public function resetWithDob(Request $request)
    {
        $rules = [
            'mobile' => ['required', 'digits:10', 'regex:/^[6-9]\d{9}$/'],
            'date_of_birth' => ['required', 'date_format:Y-m-d'],
            'password' => ['required', 'confirmed', 'max:64', PasswordRule::min(8)->mixedCase()->numbers()->symbols()],
        ];
        if (MathCaptcha::enabled()) {
            $rules['math_answer'] = ['required', 'string', 'max:5'];
        }
        $data = $request->validate($rules, [
            'mobile.regex' => 'Mobile number must be 10 digits starting with 6, 7, 8 or 9.',
            'date_of_birth.required' => 'Please enter your date of birth.',
            'date_of_birth.date_format' => 'Please enter the date of birth as a date (day, month, year).',
            'math_answer.required' => 'Please answer the sum.',
        ]);
        if (MathCaptcha::enabled() && ! app(MathCaptcha::class)->check($data['math_answer'])) {
            throw ValidationException::withMessages(['math_answer' => 'The answer to the sum is wrong. Please try the new sum.']);
        }

        $key = 'pw-dob:'.$data['mobile'];
        if (RateLimiter::tooManyAttempts($key, self::DOB_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['mobile' => "Too many attempts. Try again in {$minutes} minute(s) or ask your State Administrator."]);
        }

        $user = User::where('mobile', $data['mobile'])->first();
        $matches = $user && in_array($user->status, [UserStatus::Active, UserStatus::Locked], true)
            && $user->date_of_birth && $user->date_of_birth->toDateString() === $data['date_of_birth'];
        if (! $matches) {
            RateLimiter::hit($key, self::DOB_DECAY);
            AuditLogger::log('password_reset_dob_failed', $user, ['mobile_tail' => substr($data['mobile'], -4)], $user);

            throw ValidationException::withMessages(['date_of_birth' => 'The mobile number and date of birth do not match an active account.']);
        }

        RateLimiter::clear($key);
        $user->forceFill([
            'password' => $data['password'], 'remember_token' => Str::random(60), 'must_change_password' => false,
            'status' => UserStatus::Active, 'failed_login_attempts' => 0, 'locked_until' => null,
        ])->save();
        event(new PasswordReset($user));
        AuditLogger::log('password_reset_dob', $user, [], $user);

        return redirect()->route('login')->with('success', 'Password reset. Please sign in with your new password.');
    }

    public function sendLink(Request $request)
    {
        $request->validate(['mobile' => ['required', 'digits:10', 'regex:/^[6-9]\d{9}$/']], ['mobile.regex' => 'Mobile number must be 10 digits starting with 6, 7, 8 or 9.']);
        $user = User::where('mobile', $request->mobile)->whereNotNull('email')->first();

        if ($user) {
            Password::broker()->sendResetLink(['email' => $user->email, 'mobile' => $user->mobile]);
            AuditLogger::log('password_reset_requested', $user, [], $user);
        }

        // Same message either way, so registered numbers are not revealed
        return back()->with('status', 'If this mobile number is registered with an email address, a reset link has been sent to that email. Otherwise please contact your State Administrator.');
    }

    public function resetForm(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->email]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'mobile' => ['required', 'digits:10', 'regex:/^[6-9]\d{9}$/'],
            'password' => ['required', 'confirmed', 'max:64', PasswordRule::min(8)->mixedCase()->numbers()->symbols()],
        ], ['mobile.regex' => 'Mobile number must be 10 digits starting with 6, 7, 8 or 9.']);

        $status = Password::broker()->reset(
            $request->only('email', 'mobile', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60), 'must_change_password' => false])->save();
                event(new PasswordReset($user));
                AuditLogger::log('password_reset', $user, [], $user);
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('success', 'Password reset. Please sign in.')
            : back()->withErrors(['email' => __($status)]);
    }
}
