<x-layouts.portal title="Choose a new password" class="login-main">
    <x-slot:hero>
        <span class="reg-badge"><i class="bi bi-key"></i>Account help</span>
        <h1 class="reg-hero-title">Choose a new password</h1>
        <p class="reg-hero-sub mb-0">Confirm your mobile number and set a new password.</p>
    </x-slot:hero>

    <div class="reg-card login-card mx-auto">
        <div class="reg-block-head mb-4">
            <span class="reg-icon tone-primary"><i class="bi bi-shield-lock"></i></span>
            <div><h2>New password</h2><p>At least 8 characters with A–Z, a–z, 0–9 and a symbol.</p></div>
        </div>
        <form method="POST" action="{{ route('password.update') }}" class="reg-block-body">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ old('email', $email) }}">
            @error('email')<div class="alert alert-danger border-0 py-2 text-sm">{{ $message }}</div>@enderror
            <div class="mb-3">
                <label class="form-label" for="mobile">Mobile number <span class="req">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">+91</span>
                    <input type="tel" inputmode="numeric" maxlength="10" name="mobile" id="mobile" value="{{ old('mobile') }}" class="form-control @error('mobile') is-invalid @enderror" required>
                    @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">New password <span class="req">*</span></label>
                <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror" required autocomplete="new-password">
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label" for="password_confirmation">Confirm password <span class="req">*</span></label>
                <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" required autocomplete="new-password">
            </div>
            <button class="btn btn-primary w-100"><i class="bi bi-check2"></i>Reset password</button>
        </form>
    </div>
</x-layouts.portal>
