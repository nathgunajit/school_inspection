<x-layouts.portal title="Forgot password" class="login-main">
    <x-slot:hero>
        <span class="reg-badge"><i class="bi bi-key"></i>Account help</span>
        <h1 class="reg-hero-title">Reset your password</h1>
        <p class="reg-hero-sub mb-0">We'll send a reset link to the email address on your account.</p>
    </x-slot:hero>

    <div class="reg-card login-card mx-auto">
        <div class="reg-block-head mb-4">
            <span class="reg-icon tone-warning"><i class="bi bi-envelope"></i></span>
            <div><h2>Forgot password</h2><p>Enter your registered mobile number.</p></div>
        </div>
        <form method="POST" action="{{ route('password.email') }}" class="reg-block-body">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="mobile">Mobile number <span class="req">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">+91</span>
                    <input type="tel" inputmode="numeric" maxlength="10" pattern="[6-9][0-9]{9}" data-mobile name="mobile" id="mobile" value="{{ old('mobile') }}" class="form-control @error('mobile') is-invalid @enderror" required autofocus>
                    @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <button class="btn btn-primary w-100" data-loading-text="Sending reset link…"><i class="bi bi-envelope"></i>Send reset link</button>
        </form>
        <div class="login-help mt-3"><i class="bi bi-info-circle"></i>No email on your account? Ask your State Administrator to reset your password. You'll get a temporary password and must change it after signing in.</div>
        <div class="text-center mt-3 text-sm"><a href="{{ route('login') }}"><i class="bi bi-arrow-left"></i> Back to sign in</a></div>
    </div>
</x-layouts.portal>
