<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#4338ca">
    <title>{{ $title ?? 'Dashboard' }} · {{ config('app.name') }}</title>
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
    <a href="{{ route('dashboard') }}" class="brand">
        <span class="brand-mark"><i class="bi bi-patch-check"></i></span>
        <span>School Inspection<small class="d-block text-xs fw-normal text-secondary">Management System</small></span>
    </a>
    <nav class="flex-grow-1 overflow-auto py-2">
        <div class="nav-section">Inspection</div>
        @foreach ($nav as [$label, $route, $icon, $pattern, $perm])
            @if (! $perm || $u->can($perm))
                <a href="{{ route($route) }}" class="nav-link {{ request()->routeIs($pattern) ? 'active' : '' }}"><i class="bi {{ $icon }}"></i>{{ $label }}</a>
            @endif
        @endforeach

        @if ($u->can('approve_registrations') || $u->can('manage_users'))
            <div class="nav-section">Users</div>
            @can('approve_registrations')
                <a href="{{ route('admin.approvals.index') }}" class="nav-link {{ request()->routeIs('admin.approvals.*') ? 'active' : '' }}">
                    <i class="bi bi-person-check"></i>Pending Approvals
                    @if ($pendingCount)<span class="badge rounded-pill text-bg-warning">{{ $pendingCount }}</span>@endif
                </a>
            @endcan
            @can('manage_users')
                <a href="{{ route('admin.users.index') }}" class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}"><i class="bi bi-people"></i>User List</a>
            @endcan
            @can('state_admin')
                <a href="{{ route('admin.mobile-requests.index') }}" class="nav-link {{ request()->routeIs('admin.mobile-requests.*') ? 'active' : '' }}"><i class="bi bi-phone"></i>Mobile Changes</a>
            @endcan
        @endif

        @can('manage_masters')
            <div class="nav-section">Masters</div>
            <a href="{{ route('admin.locations.index') }}" class="nav-link {{ request()->routeIs('admin.locations.*') ? 'active' : '' }}"><i class="bi bi-geo-alt"></i>Location Master</a>
            <a href="{{ route('admin.schools.index') }}" class="nav-link {{ request()->routeIs('admin.schools.*') ? 'active' : '' }}"><i class="bi bi-building"></i>School Master</a>
            <a href="{{ route('admin.imports.index') }}" class="nav-link {{ request()->routeIs('admin.imports.*') ? 'active' : '' }}"><i class="bi bi-file-earmark-spreadsheet"></i>Excel Upload</a>
        @endcan
        @can('state_admin')
            <a href="{{ route('admin.audit.index') }}" class="nav-link {{ request()->routeIs('admin.audit.*') ? 'active' : '' }}"><i class="bi bi-shield-check"></i>Audit Log</a>
        @endcan
    </nav>
    <div class="sidebar-foot d-flex align-items-center gap-2">
        <span class="avatar">{{ $u->initials() }}</span>
        <div class="flex-grow-1 min-w-0">
            <div class="text-white text-sm fw-550 text-truncate">{{ $u->name }}</div>
            <div class="text-xs text-secondary text-truncate">{{ $u->level->label() }}</div>
        </div>
        <form method="POST" action="{{ route('logout') }}">@csrf
            <button class="btn btn-sm btn-link text-secondary" title="Sign out" aria-label="Sign out"><i class="bi bi-box-arrow-right fs-5"></i></button>
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
                <span class="avatar" style="width:32px;height:32px">{{ $u->initials() }}</span>
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
