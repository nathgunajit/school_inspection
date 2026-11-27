<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Change password, plus self-service reset by email link.
 * Phase 1 has no SMS OTP; officers without email ask the State Admin to reset.
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
        return view('auth.forgot-password');
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
