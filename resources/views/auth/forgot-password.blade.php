<x-layouts.portal title="Forgot password" class="login-main">
    <x-slot:hero>
        <span class="reg-badge"><i class="bi bi-key"></i>Account help</span>
        <h1 class="reg-hero-title">Reset your password</h1>
        <p class="reg-hero-sub mb-0">Confirm your mobile number and date of birth, then choose a new password.</p>
    </x-slot:hero>

    {{-- 27-11-2026: self-service reset with Mobile + Date of birth (PasswordController::resetWithDob) --}}
    <div class="reg-card login-card forgot-card mx-auto">
        <div class="reg-block-head mb-4">
            <span class="reg-icon tone-warning"><i class="bi bi-shield-lock"></i></span>
            <div><h2>Forgot password</h2><p>Enter your registered mobile number and date of birth, then choose a new password.</p></div>
        </div>
        <form method="POST" action="{{ route('password.dob') }}" class="reg-block-body">
            @csrf
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="mobile">Mobile number <span class="req">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">+91</span>
                        <input type="tel" inputmode="numeric" maxlength="10" pattern="[6-9][0-9]{9}" data-mobile name="mobile" id="mobile" value="{{ old('mobile') }}" class="form-control @error('mobile') is-invalid @enderror" required autofocus autocomplete="tel" placeholder="10-digit number">
                        @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="date_of_birth">Date of birth <span class="req">*</span></label>
                    <input type="date" id="date_of_birth" name="date_of_birth" value="{{ old('date_of_birth') }}" required autocomplete="bday" placeholder="Select date"
                           min="{{ now()->subYears(\App\Models\User::MAX_AGE)->toDateString() }}" max="{{ now()->subYears(\App\Models\User::MIN_AGE)->toDateString() }}"
                           class="form-control @error('date_of_birth') is-invalid @enderror">
                    @error('date_of_birth')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="password">New password <span class="req">*</span></label>
                    <div class="input-group">
                        <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror" required minlength="8" maxlength="64" autocomplete="new-password">
                        @include('partials.pw-toggle')
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="password_confirmation">Confirm password <span class="req">*</span></label>
                    <div class="input-group">
                        <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" required maxlength="64" autocomplete="new-password">
                        @include('partials.pw-toggle')
                    </div>
                </div>
                <div class="col-12"><div class="form-text mt-0"><i class="bi bi-info-circle me-1"></i>8 to 64 characters with A–Z, a–z, 0–9 and a symbol (e.g. @ # $).</div></div>
            </div>
            @if ($mathCaptcha)
                <div class="mt-3">@include('partials.math-captcha')</div>
            @endif
            <button class="btn btn-primary w-100 mt-3" data-loading-text="Resetting password…"><i class="bi bi-check2-circle"></i>Reset password</button>
        </form>

        {{-- 27-11-2026: the "Send a reset link instead" email option is hidden for now; route password.email still works --}}
        <div class="login-help mt-3"><i class="bi bi-info-circle"></i>No date of birth on your account? Ask your State Administrator to reset your password. You'll get a temporary password to change after signing in.</div>
        <div class="text-center mt-3 text-sm"><a href="{{ route('login') }}"><i class="bi bi-arrow-left"></i> Back to sign in</a></div>
    </div>
</x-layouts.portal>
