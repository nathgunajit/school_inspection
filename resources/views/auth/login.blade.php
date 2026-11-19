<x-layouts.portal title="Sign in" class="login-main">
    <x-slot:hero>
        <div class="row align-items-center g-3">
            <div class="col-lg-7">
                <span class="reg-badge"><i class="bi bi-shield-check"></i>Inspecting Officer Login</span>
                <h1 class="reg-hero-title">Welcome to School Inspection</h1>
                <p class="reg-hero-sub mb-0">Sign in to inspect schools, record findings with geo-stamped photographs and submit reports for your jurisdiction.</p>
            </div>
            <div class="col-lg-5 d-none d-lg-block">
                <ul class="reg-steps">
                    <li><span><i class="bi bi-search"></i></span>Find school by UDISE code</li>
                    <li><span><i class="bi bi-ui-checks-grid"></i></span>Fill the 7-step inspection form</li>
                    <li><span><i class="bi bi-camera"></i></span>Add photos &amp; submit report</li>
                </ul>
            </div>
        </div>
    </x-slot:hero>

    <div class="login-grid">
        {{-- Sign-in card --}}
        <div class="reg-card login-card">
            <div class="reg-block-head mb-4">
                <span class="reg-icon tone-primary"><i class="bi bi-box-arrow-in-right"></i></span>
                <div><h2>Sign in</h2><p>Use the mobile number you registered with.</p></div>
            </div>

            <form method="POST" action="{{ route('login') }}" novalidate class="reg-block-body">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="mobile">Mobile number <span class="req">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">+91</span>
                        <input type="tel" inputmode="numeric" maxlength="10" pattern="[6-9][0-9]{9}" data-mobile id="mobile" name="mobile" value="{{ old('mobile') }}"
                               class="form-control @error('mobile') is-invalid @enderror" placeholder="10-digit mobile number" autocomplete="username" required autofocus>
                        @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-baseline">
                        <label class="form-label" for="password">Password <span class="req">*</span></label>
                        <a href="{{ route('password.request') }}" class="text-xs">Forgot password?</a>
                    </div>
                    <div class="input-group">
                        <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" placeholder="Your password" autocomplete="current-password" required>
                        <button type="button" class="btn btn-light border" onclick="const p=document.getElementById('password');p.type=p.type==='password'?'text':'password';this.firstElementChild.classList.toggle('bi-eye-slash')" aria-label="Show password"><i class="bi bi-eye"></i></button>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                @if ($needsCaptcha && config('inspection.captcha_enabled'))
                    @include('partials.captcha')
                @endif

                <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-right"></i>Sign in</button>
            </form>
        </div>

        {{-- New officer card --}}
        <div class="reg-card login-side">
            <div class="reg-block-head mb-3">
                <span class="reg-icon tone-success"><i class="bi bi-person-plus"></i></span>
                <div><h2>New officer?</h2><p>State, District, Block and Cluster level officers can register online.</p></div>
            </div>
            <ul class="login-points">
                <li><i class="bi bi-check2-circle"></i>Keep your posting order or department ID card ready</li>
                <li><i class="bi bi-check2-circle"></i>Your request is approved by a higher officer</li>
                <li><i class="bi bi-check2-circle"></i>You can sign in once approved</li>
            </ul>
            <a href="{{ route('register') }}" class="btn btn-soft-primary w-100"><i class="bi bi-person-plus"></i>Register as officer</a>
            <div class="login-help"><i class="bi bi-headset"></i>Need help? Contact your Block or District office, or the State Administrator.</div>
        </div>
    </div>

    {{-- Demo accounts (non-production only, see LoginController::demoUsers) --}}
    @if ($demoUsers->isNotEmpty())
        <section class="reg-card demo-panel mt-3" aria-labelledby="demo-title">
            <div class="demo-head">
                <div class="reg-block-head">
                    <span class="reg-icon tone-warning"><i class="bi bi-people"></i></span>
                    <div><h2 id="demo-title">Demo accounts</h2><p>Testing only. Tap an officer to fill the sign-in form.</p></div>
                </div>
                <span class="demo-pass"><i class="bi bi-key"></i>Password for all: <code>Demo@1234</code></span>
            </div>
            <div class="demo-groups">
                @foreach ($demoUsers as $level => $users)
                    <div class="demo-group">
                        <div class="demo-level">{{ $level }}</div>
                        @foreach ($users as $d)
                            <button type="button" class="demo-user" data-demo-mobile="{{ $d->mobile }}" title="Use {{ $d->name }}">
                                <span class="min-w-0">
                                    <span class="n">{{ $d->name }}</span>
                                    <span class="r">{{ $d->designation?->label }}</span>
                                </span>
                                <span class="m">{{ $d->mobile }}</span>
                            </button>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </section>

        @push('scripts')
            <script>
                document.querySelectorAll('[data-demo-mobile]').forEach((b) => b.addEventListener('click', () => {
                    document.getElementById('mobile').value = b.dataset.demoMobile;
                    document.getElementById('password').value = 'Demo@1234';
                    document.querySelectorAll('.demo-user.is-picked').forEach((x) => x.classList.remove('is-picked'));
                    b.classList.add('is-picked');
                    const form = document.getElementById('mobile').closest('form');
                    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    (form.querySelector('.captcha-input') || form.querySelector('button[type=submit]')).focus({ preventScroll: true });
                }));
            </script>
        @endpush
    @endif
</x-layouts.portal>
