<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1565c0">
    <title>{{ $title ?? 'Dashboard' }} · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('logo/SSA LOGO.png') }}">
    <script>try { if (localStorage.getItem('si-sidebar') === 'mini') document.documentElement.classList.add('sidebar-mini'); } catch (e) {}</script>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
    @stack('head')
</head>
<body>
@php
    $u = auth()->user();
    $nav = [
        ['Dashboard', 'dashboard', 'bi-grid-1x2', 'dashboard', null],
        ['Find School', 'schools.index', 'bi-search', 'schools.*', 'conduct_inspections'],
        ['My Inspections', 'inspections.mine', 'bi-clipboard-check', 'inspections.mine', 'conduct_inspections'],
        ['Inspections in Area', 'inspections.area', 'bi-map', 'inspections.area', 'view_reports'],
    ];
    $pendingCount = $u->can('approve_registrations') ? app(\App\Services\ApproverResolver::class)->pendingFor($u)->count() : 0;
@endphp

<aside class="app-sidebar" aria-label="Main navigation">
    <div class="brand">
        <a href="{{ route('dashboard') }}" class="brand-mark" title="Samagra Shiksha, Assam"><img src="{{ asset('logo/SSA LOGO.png') }}" alt="Samagra Shiksha"></a>
        <a href="{{ route('dashboard') }}" class="brand-text text-white text-decoration-none">School Inspection<small>Samagra Shiksha, Assam</small></a>
        <button type="button" class="sidebar-toggle d-none d-lg-grid" data-sidebar-mini title="Collapse / expand menu" aria-label="Collapse or expand menu"><i class="bi bi-chevron-double-left"></i></button>
    </div>
    @php
        $link = fn ($route, $pattern, $icon, $label, $extra = '') => '<a href="'.route($route).'" class="nav-link '.(request()->routeIs($pattern) ? 'active' : '').'" title="'.e($label).'" data-mini-tip><i class="bi '.$icon.'"></i><span>'.e($label).'</span>'.$extra.'</a>';
    @endphp
    <nav class="flex-grow-1 overflow-auto py-1">
        <div class="nav-section">Inspection</div>
        @foreach ($nav as [$label, $route, $icon, $pattern, $perm])
            @if (! $perm || $u->can($perm)){!! $link($route, $pattern, $icon, $label) !!}@endif
        @endforeach

        @if ($u->can('approve_registrations') || $u->can('manage_users'))
            <div class="nav-section">Users</div>
            @can('approve_registrations')
                {!! $link('admin.approvals.index', 'admin.approvals.*', 'bi-person-check', 'Pending Approvals', $pendingCount ? '<span class="badge rounded-pill text-bg-warning">'.$pendingCount.'</span>' : '') !!}
            @endcan
            @can('manage_users'){!! $link('admin.users.index', 'admin.users.*', 'bi-people', 'User List') !!}@endcan
            @can('state_admin'){!! $link('admin.mobile-requests.index', 'admin.mobile-requests.*', 'bi-phone', 'Mobile Changes') !!}@endcan
        @endif

        @can('manage_masters')
            <div class="nav-section">Masters</div>
            {!! $link('admin.locations.index', 'admin.locations.*', 'bi-geo-alt', 'Location Master') !!}
            {!! $link('admin.schools.index', 'admin.schools.*', 'bi-building', 'School Master') !!}
            {!! $link('admin.imports.index', 'admin.imports.*', 'bi-file-earmark-spreadsheet', 'Excel Upload') !!}
        @endcan
        @can('state_admin'){!! $link('admin.audit.index', 'admin.audit.*', 'bi-shield-check', 'Audit Log') !!}@endcan
    </nav>
    <div class="sidebar-logos">
        <img src="{{ asset('logo/ASSAM GOVT LOGO.png') }}" alt="Government of Assam">
        <img src="{{ asset('logo/SSA LOGO.png') }}" alt="Samagra Shiksha">
    </div>
    <div class="sidebar-foot d-flex align-items-center gap-2">
        <x-user-avatar :user="$u" style="background:rgba(255,255,255,.15);color:#fff" />
        <div class="flex-grow-1 min-w-0 foot-text">
            <div class="text-white text-sm fw-550 text-truncate">{{ $u->name }}</div>
            <div class="text-xs text-truncate" style="color:#9fb6dd">{{ $u->level->label() }}</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">@csrf
            <button class="btn btn-sm btn-link p-1" style="color:#9fb6dd;min-height:32px" title="Sign out" aria-label="Sign out"><i class="bi bi-box-arrow-right fs-5"></i></button>
        </form>
    </div>
</aside>

<div class="app-main">
    <header class="app-topbar">
        <button class="btn btn-light btn-icon d-lg-none" data-toggle-sidebar aria-label="Open menu"><i class="bi bi-list fs-5"></i></button>
        @isset($back)
            <a href="{{ $back }}" class="btn btn-light btn-icon" aria-label="Back"><i class="bi bi-arrow-left"></i></a>
        @endisset
        <h1 class="page-title flex-grow-1">{{ $title ?? 'Dashboard' }}</h1>
        {{ $actions ?? '' }}
        <div class="dropdown">
            <button class="btn btn-light d-flex align-items-center gap-2 px-2" data-bs-toggle="dropdown" aria-label="Account menu">
                <x-user-avatar :user="$u" style="width:32px;height:32px" />
                <i class="bi bi-chevron-down text-xs d-none d-sm-inline"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" style="min-width:240px">
                <li class="px-2 py-2">
                    <div class="fw-semibold">{{ $u->name }}</div>
                    <div class="text-xs text-secondary">{{ $u->level->label() }} · {{ $u->mobile }}</div>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item rounded" href="{{ route('profile') }}"><i class="bi bi-person me-2"></i>My Profile</a></li>
                <li><a class="dropdown-item rounded" href="{{ route('password.change') }}"><i class="bi bi-key me-2"></i>Change Password</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><form method="POST" action="{{ route('logout') }}">@csrf<button class="dropdown-item rounded text-danger"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button></form></li>
            </ul>
        </div>
    </header>

    <main class="app-content">
        @include('partials.flash')
        {{ $slot }}
    </main>
</div>

<nav class="bottom-nav" aria-label="Quick navigation">
    <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}"><i class="bi bi-house{{ request()->routeIs('dashboard') ? '-fill' : '' }}"></i>Home</a>
    <a href="{{ route('inspections.mine') }}" class="{{ request()->routeIs('inspections.mine') ? 'active' : '' }}"><i class="bi bi-clipboard-check"></i>My Visits</a>
    <a href="{{ route('schools.index') }}" class="fab" aria-label="New inspection"><i class="bi bi-plus-lg"></i></a>
    @can('view_reports')
        <a href="{{ route('inspections.area') }}" class="{{ request()->routeIs('inspections.area') ? 'active' : '' }}"><i class="bi bi-map"></i>Area</a>
    @endcan
    <a href="{{ route('profile') }}" class="{{ request()->routeIs('profile') ? 'active' : '' }}"><i class="bi bi-person{{ request()->routeIs('profile') ? '-fill' : '' }}"></i>Profile</a>
</nav>

@stack('scripts')
</body>
</html>
