<x-layouts.guest title="Sign in">
    <h1 class="h3 fw-bold mb-1">Welcome back</h1>
    <p class="text-secondary mb-4">Sign in with your registered mobile number.</p>

    <form method="POST" action="{{ route('login') }}" novalidate>
        @csrf
        <div class="mb-3">
            <label class="form-label" for="mobile">Mobile number</label>
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-phone text-secondary"></i></span>
                <input type="tel" inputmode="numeric" maxlength="10" pattern="\d{10}" id="mobile" name="mobile" value="{{ old('mobile') }}"
                       class="form-control @error('mobile') is-invalid @enderror" placeholder="10-digit mobile number" autocomplete="username" required autofocus>
                @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="mb-3">
            <div class="d-flex justify-content-between">
                <label class="form-label" for="password">Password</label>
                <a href="{{ route('password.request') }}" class="text-sm">Forgot password?</a>
            </div>
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-lock text-secondary"></i></span>
                <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" placeholder="Your password" autocomplete="current-password" required>
                <button type="button" class="btn btn-outline-secondary border-start-0" style="border-color:var(--bs-border-color)" onclick="const p=document.getElementById('password');p.type=p.type==='password'?'text':'password';this.firstElementChild.classList.toggle('bi-eye-slash')" aria-label="Show password"><i class="bi bi-eye"></i></button>
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>

        @if ($needsCaptcha && config('inspection.captcha_enabled'))
            @include('partials.captcha')
        @endif

        <button class="btn btn-primary w-100 btn-lg mt-2" type="submit"><i class="bi bi-box-arrow-in-right"></i>Sign in</button>
    </form>

    <div class="text-center mt-4 pt-3 border-top">
        <span class="text-secondary">New officer?</span>
        <a href="{{ route('register') }}" class="fw-semibold">Register here</a>
    </div>
</x-layouts.guest>
