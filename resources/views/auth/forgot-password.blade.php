<x-layouts.guest title="Forgot password">
    <a href="{{ route('login') }}" class="text-sm d-inline-flex align-items-center gap-1 mb-3"><i class="bi bi-arrow-left"></i>Back to sign in</a>
    <h1 class="h3 fw-bold mb-1">Reset your password</h1>
    <p class="text-secondary mb-4">Enter your registered mobile number. If an email address is on your account, we'll send a reset link there.</p>
    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="mobile">Mobile number</label>
            <input type="tel" inputmode="numeric" maxlength="10" name="mobile" id="mobile" value="{{ old('mobile') }}" class="form-control @error('mobile') is-invalid @enderror" required autofocus>
            @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button class="btn btn-primary w-100"><i class="bi bi-envelope"></i>Send reset link</button>
    </form>
    <div class="alert alert-light border mt-4 text-sm mb-0"><i class="bi bi-info-circle me-1"></i>No email on your account? Ask your <strong>State Administrator</strong> to reset your password. You will get a temporary password and must change it after signing in.</div>
</x-layouts.guest>
