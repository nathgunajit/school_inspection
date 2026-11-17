<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1565c0">
    <title>{{ $title ?? 'Welcome' }} · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('logo/SSA LOGO.png') }}">
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
    @stack('head')
</head>
<body>
<div class="auth-wrap">
    <aside class="auth-aside">
        <span class="bubble" style="width:340px;height:340px;right:-120px;top:-120px"></span>
        <span class="bubble" style="width:220px;height:220px;left:-80px;bottom:60px"></span>
        <span class="bubble" style="width:120px;height:120px;right:18%;bottom:22%;background:rgba(243,146,35,.18)"></span>
        <div class="aside-logos">
            <span><img src="{{ asset('logo/ASSAM GOVT LOGO.png') }}" alt="Government of Assam"></span>
            <span><img src="{{ asset('logo/SSA LOGO.png') }}" alt="Samagra Shiksha"></span>
        </div>
        <div class="position-relative" style="max-width:430px">
            <div class="text-white-50 text-sm fw-semibold text-uppercase mb-2" style="letter-spacing:.12em">Samagra Shiksha, Assam</div>
            <h2 class="fw-bold text-white mb-3" style="font-size:2rem;letter-spacing:-.03em">School Inspection<br>Management System</h2>
            <p class="text-white-50 mb-4">One digital platform for school inspections across State, District, Block and Cluster levels.</p>
            <div class="feature"><i class="bi bi-ui-checks-grid"></i><div><div class="fw-semibold">Digital questionnaire</div><div class="text-white-50 text-sm">Guided steps with auto-save.</div></div></div>
            <div class="feature"><i class="bi bi-camera"></i><div><div class="fw-semibold">Geo-stamped photographs</div><div class="text-white-50 text-sm">Date, time and location on every photo.</div></div></div>
            <div class="feature"><i class="bi bi-shield-lock"></i><div><div class="fw-semibold">Secure &amp; tamper-proof</div><div class="text-white-50 text-sm">Locked reports and a full audit trail.</div></div></div>
        </div>
        <div class="text-white-50 text-xs position-relative">&copy; {{ date('Y') }} Government of Assam · Samagra Shiksha</div>
    </aside>
    <main class="auth-panel">
        <div class="auth-card {{ $wide ?? false ? 'wide' : '' }}">
            <div class="auth-logos">
                <img src="{{ asset('logo/ASSAM GOVT LOGO.png') }}" alt="Government of Assam" class="logo-govt">
                <span class="divider"></span>
                <img src="{{ asset('logo/SSA LOGO.png') }}" alt="Samagra Shiksha" class="logo-ss">
            </div>
            @include('partials.flash')
            {{ $slot }}
        </div>
    </main>
</div>
@stack('scripts')
</body>
</html>
