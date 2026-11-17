<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#4338ca">
    <title>{{ $title ?? 'Welcome' }} · {{ config('app.name') }}</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="bg-white">
<div class="auth-wrap">
    <aside class="auth-aside">
        <span class="bubble" style="width:340px;height:340px;right:-120px;top:-120px"></span>
        <span class="bubble" style="width:220px;height:220px;left:-80px;bottom:60px"></span>
        <div class="d-flex align-items-center gap-3 position-relative">
            <span class="d-grid place-items-center rounded-4 bg-white bg-opacity-25" style="width:48px;height:48px;display:grid;place-items:center"><i class="bi bi-patch-check fs-4"></i></span>
            <div><div class="fw-bold fs-5">School Inspection</div><div class="text-white-50 text-sm">Management System</div></div>
        </div>
        <div class="position-relative" style="max-width:440px">
            <h2 class="display-6 fw-bold text-white mb-3" style="letter-spacing:-.03em">Inspect. Evidence. Improve.</h2>
            <p class="text-white-50 mb-4">A single digital platform for school inspections across State, District, Block and Cluster levels.</p>
            <div class="feature"><i class="bi bi-ui-checks-grid"></i><div><div class="fw-semibold">Digital questionnaire</div><div class="text-white-50 text-sm">Replaces the paper form with guided steps and auto-save.</div></div></div>
            <div class="feature"><i class="bi bi-camera"></i><div><div class="fw-semibold">Geo-stamped evidence</div><div class="text-white-50 text-sm">In-app photos stamped with date, time and location.</div></div></div>
            <div class="feature"><i class="bi bi-shield-lock"></i><div><div class="fw-semibold">Secure &amp; tamper-proof</div><div class="text-white-50 text-sm">Locked reports, jurisdiction-based access and a full audit trail.</div></div></div>
        </div>
        <div class="text-white-50 text-xs position-relative">&copy; {{ date('Y') }} Department of School Education</div>
    </aside>
    <main class="auth-panel">
        <div class="auth-card {{ $wide ?? false ? 'wide' : '' }}">
            <div class="auth-brand d-lg-none">
                <span class="brand-mark"><i class="bi bi-patch-check"></i></span>
                <div><div class="fw-bold">School Inspection</div><div class="text-secondary text-xs">Management System</div></div>
            </div>
            @include('partials.flash')
            {{ $slot }}
        </div>
    </main>
</div>
@stack('scripts')
</body>
</html>
