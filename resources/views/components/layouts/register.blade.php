@props(['title' => 'Officer Registration'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1565c0">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('logo/SSA LOGO.png') }}">
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body class="reg-page">
    {{-- Official header strip --}}
    <header class="reg-topbar">
        <div class="container-xl d-flex align-items-center gap-3">
            <img src="{{ asset('logo/ASSAM GOVT LOGO.png') }}" alt="Government of Assam" class="reg-logo-govt">
            <div class="reg-topbar-title d-none d-md-block">
                <div class="t1">Government of Assam</div>
                <div class="t2">Samagra Shiksha, Assam · School Inspection Management System</div>
            </div>
            <img src="{{ asset('logo/SSA LOGO.png') }}" alt="Samagra Shiksha" class="reg-logo-ss ms-auto">
        </div>
    </header>

    {{-- Education themed banner --}}
    <section class="reg-hero">
        <div class="reg-hero-pattern" aria-hidden="true">
            <i class="bi bi-mortarboard"></i><i class="bi bi-book"></i><i class="bi bi-building"></i><i class="bi bi-pencil"></i>
            <i class="bi bi-journal-check"></i><i class="bi bi-easel"></i><i class="bi bi-backpack"></i><i class="bi bi-clipboard-check"></i>
        </div>
        <div class="container-xl position-relative">
            {{ $hero ?? '' }}
        </div>
    </section>

    <main class="container-xl reg-main">
        @include('partials.flash')
        {{ $slot }}
    </main>

    <footer class="reg-footer">
        <div class="container-xl d-flex flex-column flex-md-row justify-content-between gap-1">
            <span>&copy; {{ date('Y') }} Government of Assam · Samagra Shiksha, Assam</span>
            <span>For inspecting officers of the Education Department only</span>
        </div>
    </footer>
    @stack('scripts')
</body>
</html>
