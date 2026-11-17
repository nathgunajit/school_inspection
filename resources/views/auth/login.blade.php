<x-layouts.portal title="Officer Login">
    <div class="portal-login">
        {{-- Left: branding panel --}}
        <section class="portal-login-brand">
            <div class="reg-hero-pattern" aria-hidden="true">
                <i class="bi bi-mortarboard"></i><i class="bi bi-book"></i><i class="bi bi-building"></i><i class="bi bi-pencil"></i>
                <i class="bi bi-journal-check"></i><i class="bi bi-easel"></i>
            </div>
            <div class="position-relative">
                <div class="brand-logos">
                    <span><img src="{{ asset('logo/ASSAM GOVT LOGO.png') }}" alt="Government of Assam"></span>
                    <span><img src="{{ asset('logo/SSA LOGO.png') }}" alt="Samagra Shiksha"></span>
                </div>
                <div class="brand-kicker">Samagra Shiksha, Assam</div>
                <h1 class="brand-title">School Inspection<br>Management System</h1>
                <p class="brand-text">The official portal for State, District, Block and Cluster level officers to inspect schools and submit reports.</p>
            </div>
            <ul class="brand-points position-relative">
                <li><i class="bi bi-search"></i>Find schools by UDISE code</li>
                <li><i class="bi bi-ui-checks-grid"></i>Digital inspection form with auto-save</li>
                <li><i class="bi bi-camera"></i>Geo-stamped photographs</li>
                <li><i class="bi bi-shield-lock"></i>Secure, jurisdiction-based access</li>
            </ul>
        </section>

        {{-- Right: login form --}}
        <section class="portal-login-form">
            <div class="form-head">
                <span class="form-head-icon"><i class="bi bi-person-lock"></i></span>
                <h2>Officer Login</h2>
                <p>Sign in with your registered mobile number</p>
            </div>

            @include('partials.flash')

            <form method="POST" action="{{ route('login') }}" novalidate>
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="mobile">Mobile number</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-phone"></i></span>
                        <input type="tel" inputmode="numeric" maxlength="10" pattern="\d{10}" id="mobile" name="mobile" value="{{ old('mobile') }}"
                               class="form-control @error('mobile') is-invalid @enderror" placeholder="Enter 10-digit mobile number" autocomplete="username" required autofocus>
                        @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" placeholder="Enter password" autocomplete="current-password" required>
                        <button type="button" class="btn btn-light border" onclick="const p=document.getElementById('password');p.type=p.type==='password'?'text':'password';this.firstElementChild.classList.toggle('bi-eye-slash')" aria-label="Show password"><i class="bi bi-eye"></i></button>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="d-flex justify-content-end mb-3">
                    <a href="{{ route('password.request') }}" class="text-sm">Forgot password?</a>
                </div>

                @if ($needsCaptcha && config('inspection.captcha_enabled'))
                    @include('partials.captcha')
                @endif

                <button class="btn btn-primary btn-lg w-100" type="submit"><i class="bi bi-box-arrow-in-right"></i>Login</button>
            </form>

            <div class="form-divider"><span>New to the portal?</span></div>
            <a href="{{ route('register') }}" class="btn btn-outline-primary w-100"><i class="bi bi-person-plus"></i>Register as Inspecting Officer</a>

            <p class="form-note"><i class="bi bi-info-circle"></i>New registrations are approved by a higher officer before you can log in. For help, contact your Block or District office.</p>
        </section>
    </div>
</x-layouts.portal>
