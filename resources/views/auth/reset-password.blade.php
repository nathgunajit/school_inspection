<x-layouts.guest title="Choose a new password">
    <h1 class="h3 fw-bold mb-1">Choose a new password</h1>
    <p class="text-secondary mb-4">Confirm your mobile number and set a new password.</p>
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ old('email', $email) }}">
        @error('email')<div class="alert alert-danger border-0">{{ $message }}</div>@enderror
        <div class="mb-3">
            <label class="form-label" for="mobile">Mobile number</label>
            <input type="tel" inputmode="numeric" maxlength="10" name="mobile" id="mobile" value="{{ old('mobile') }}" class="form-control @error('mobile') is-invalid @enderror" required>
            @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">New password</label>
            <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror" required autocomplete="new-password">
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-4">
            <label class="form-label" for="password_confirmation">Confirm password</label>
            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" required autocomplete="new-password">
        </div>
        <button class="btn btn-primary w-100">Reset password</button>
    </form>
</x-layouts.guest>
