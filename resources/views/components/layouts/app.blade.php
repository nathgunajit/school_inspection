<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1565c0">
    @include('partials.password-key')
    {{-- Idle sign-out warning (Modification V3): minutes of inactivity before the session ends --}}
    <meta name="session-lifetime" content="{{ (int) config('session.lifetime') }}">
    <meta name="session-ping" content="{{ route('session.ping') }}">
    <meta name="session-expired-url" content="{{ route('login', ['expired' => 1]) }}">
    <title>{{ $title ?? 'Dashboard' }} · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('logo/SSA LOGO.png') }}">
    <script>try { if (localStorage.getItem('si-sidebar') !== 'full') document.documentElement.classList.add('sidebar-mini'); } catch (e) { document.documentElement.classList.add('sidebar-mini'); }</script>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
    @stack('head')
</head>
{{-- 25-11-2026: notice-badge.js keeps the unread counters live (not for the State Admin, who has none) --}}
<body @if (auth()->user() && ! auth()->user()->isStateAdmin()) data-notice-count-url="{{ route('notices.unread-count') }}" @endif>
<x-page-loader />
@php
    $u = auth()->user();
    // 25-11-2026: sibling pages merged into one item each (tabs on the pages: <x-page-tabs>); the gate is a closure
    $can = fn (...$p) => fn ($u) => collect($p)->contains(fn ($x) => $u->can($x));
    $nav = [
        ['Dashboard', 'dashboard', 'bi-grid-1x2', 'dashboard', null],
        ['Today\'s Schools', 'today.index', 'bi-signpost-2', ['today.index', 'today.assign*'], $can('conduct_inspections')], // V14 item 5
        ['Schools', 'schools.index', 'bi-buildings', ['schools.index', 'schools.show', 'schools.blank-form', 'schools.mine*'], $can('conduct_inspections')], // Find School | My Schools
        ['Inspections', $u->can('conduct_inspections') ? 'inspections.mine' : 'inspections.area', 'bi-clipboard-check', ['inspections.mine', 'inspections.area'], $can('conduct_inspections', 'view_reports')], // Mine | In My Area
        ['Reports', $u->can('view_reports') ? 'reports.coverage' : 'reports.ratings', 'bi-bar-chart-line', ['reports.coverage', 'reports.coverage.export*', 'reports.ratings*', 'reports.network*'], $can('view_reports', 'state_admin')], // Coverage | Rating (State Admin) | Network Issues
        ['Map View', 'map.index', 'bi-map', 'map.*', $can('view_reports')],
        ['Notices & Orders', 'notices.index', 'bi-megaphone', 'notices.*', null], // V14 item 1 (after Map View since 25-11-2026)
        ['Inspection Schedules', 'schedules.index', 'bi-calendar-week', 'schedules.*', $can('view_reports')],
        ['Holiday Calendar', 'holidays.index', 'bi-calendar-heart', 'holidays.*', null], // V14 (25-11-2026)
    ];
    $usersNav = array_values(array_filter([
        $u->can('approve_registrations') ? ['admin.approvals.index', 'admin.approvals.*', 'bi-person-check', 'Pending Approvals', 'pending'] : null,
        $u->can('manage_users') ? ['admin.users.index', 'admin.users.*', 'bi-people', 'User List', null] : null,
        $u->can('state_admin') ? ['admin.mobile-requests.index', 'admin.mobile-requests.*', 'bi-phone', 'Mobile Changes', null] : null,
    ]));
    // 25-11-2026: Masters (data) and Admin Settings (settings and monitoring) are separate groups
    $mastersNav = $u->can('manage_masters') ? [
        ['admin.locations.index', 'admin.locations.*', 'bi-geo-alt', 'Location Master', null],
        ['admin.constituencies.index', 'admin.constituencies.*', 'bi-bank', 'AC / PC Master', null],
        ['admin.schools.index', 'admin.schools.*', 'bi-building', 'School Master', null],
        ['admin.tlm.index', 'admin.tlm.*', 'bi-box-seam', 'TLM Master', null],
        ['admin.notice-types.index', 'admin.notice-types.*', 'bi-tags', 'Notice Types', null], // 25-11-2026
        ['admin.imports.index', 'admin.imports.*', 'bi-file-earmark-spreadsheet', 'Excel Upload', null],
    ] : [];
    $settingsNav = array_values(array_filter([
        $u->can('manage_masters') ? ['admin.rating.index', 'admin.rating.*', 'bi-star-half', 'Rating Settings', null] : null,
        $u->can('configure_coverage') ? ['reports.coverage.settings', 'reports.coverage.settings*', 'bi-sliders', 'Coverage Settings', null] : null,
        $u->can('manage_holidays') ? ['today.settings', 'today.settings*', 'bi-signpost-split', 'Today\'s Schools Settings', null] : null,
        $u->can('state_admin') ? ['admin.login-report.index', 'admin.login-report.*', 'bi-person-lock', 'Login Monitoring', null] : null,
        $u->can('state_admin') ? ['admin.audit.index', 'admin.audit.*', 'bi-shield-check', 'Audit Log', null] : null,
        // 25-11-2026: local server only (never production; TEST_DATA_CLEANUP)
        $u->can('state_admin') && \App\Services\TestDataCleaner::enabled() ? ['admin.test-data.index', 'admin.test-data.*', 'bi-trash3', 'Clean Test Data', null] : null,
    ]));
    $pendingCount = $u->can('approve_registrations') ? app(\App\Services\ApproverResolver::class)->pendingCountFor($u) : 0;
    $noticeCount = app(\App\Services\NoticeService::class)->unreadCount($u); // V14 item 1
    // 25-11-2026: always rendered (hidden at 0) so notice-badge.js can update it live
    $navBadge = ['notices.index' => '<span class="badge rounded-pill text-bg-warning" data-notice-count'.($noticeCount ? '' : ' hidden').'>'.$noticeCount.'</span>'];
@endphp

<aside class="app-sidebar" aria-label="Main navigation">
    <div class="brand">
        <a href="{{ route('dashboard') }}" class="brand-mark" title="Samagra Shiksha, Assam"><img src="{{ asset('logo/SSA LOGO.png') }}" alt="Samagra Shiksha"></a>
        <a href="{{ route('dashboard') }}" class="brand-text text-white text-decoration-none">School Inspection<small>Samagra Shiksha, Assam</small></a>
        <button type="button" class="sidebar-toggle d-none d-lg-grid" data-sidebar-mini data-mini-tip title="Collapse menu" aria-label="Collapse menu" aria-expanded="true"><i class="bi bi-chevron-double-left"></i></button>
    </div>
    @can('conduct_inspections')
        <div class="sidebar-cta">
            <a href="{{ route('schools.index') }}" class="btn-new" title="New inspection" data-mini-tip><i class="bi bi-plus-lg"></i><span>New inspection</span></a>
        </div>
    @endcan
    @php
        $link = fn ($route, $pattern, $icon, $label, $extra = '') => '<a href="'.route($route).'" class="nav-link '.(request()->routeIs($pattern) ? 'active' : '').'" title="'.e($label).'" data-mini-tip><i class="bi '.$icon.'"></i><span>'.e($label).'</span>'.$extra.'</a>';
    @endphp
    <nav class="flex-grow-1 overflow-auto py-1">
        <div class="nav-section">Inspection</div>
        @foreach ($nav as [$label, $route, $icon, $pattern, $perm])
            @if (! $perm || $perm($u)){!! $link($route, $pattern, $icon, $label, $navBadge[$route] ?? '') !!}@endif
        @endforeach

        @if ($usersNav || $mastersNav || $settingsNav)<div class="nav-section">Administration</div>@endif
        {{-- 25-11-2026: Users, Masters and Admin Settings fold away; a group is open while one of its pages is open --}}
        @foreach ([['nav-users', 'Users', 'bi-people', $usersNav], ['nav-masters', 'Masters', 'bi-database', $mastersNav], ['nav-settings', 'Admin Settings', 'bi-gear', $settingsNav]] as [$gid, $glabel, $gicon, $items])
            @continue(empty($items))
            @php $open = collect($items)->contains(fn ($i) => request()->routeIs($i[1])); @endphp
            <button type="button" class="nav-link nav-group-toggle {{ $open ? '' : 'collapsed' }}" data-bs-toggle="collapse" data-bs-target="#{{ $gid }}" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="{{ $gid }}">
                <i class="bi {{ $gicon }}"></i><span>{{ $glabel }}</span>
                @if ($gid === 'nav-users' && $pendingCount)<span class="badge rounded-pill text-bg-warning">{{ $pendingCount }}</span>@endif
                <i class="bi bi-chevron-down nav-group-caret"></i>
            </button>
            <div class="collapse nav-group {{ $open ? 'show' : '' }}" id="{{ $gid }}">
                @foreach ($items as [$route, $pattern, $icon, $label, $badge])
                    {!! $link($route, $pattern, $icon, $label, $badge === 'pending' && $pendingCount ? '<span class="badge rounded-pill text-bg-warning">'.$pendingCount.'</span>' : '') !!}
                @endforeach
            </div>
        @endforeach
    </nav>
    <div class="sidebar-note">Government of Assam</div>
</aside>

<div class="app-main">
    <header class="app-topbar">
        <button class="btn btn-light btn-icon d-lg-none" data-toggle-sidebar aria-label="Open menu"><i class="bi bi-list fs-5"></i></button>
        @isset($back)
            <a href="{{ $back }}" class="btn btn-light btn-icon" aria-label="Back"><i class="bi bi-arrow-left"></i></a>
        @endisset
        <h1 class="page-title flex-grow-1">{{ $title ?? 'Dashboard' }}</h1>
        {{ $actions ?? '' }}
        <div class="topbar-right">
            @unless ($u->isStateAdmin())
                <a href="{{ route('notices.index', ['unread' => 1]) }}" class="topbar-icon" title="Unread notices" aria-label="Unread notices" data-notice-count-wrap @if (! $noticeCount) hidden @endif>
                    <i class="bi bi-megaphone"></i><span class="dot" data-notice-count data-max="9">{{ $noticeCount > 9 ? '9+' : $noticeCount }}</span>
                </a>
            @endunless
            @if ($pendingCount)
                <a href="{{ route('admin.approvals.index') }}" class="topbar-icon" title="{{ $pendingCount }} pending approval{{ $pendingCount > 1 ? 's' : '' }}" aria-label="Pending approvals">
                    <i class="bi bi-bell"></i><span class="dot">{{ $pendingCount > 9 ? '9+' : $pendingCount }}</span>
                </a>
            @endif
            @php
                $hour = (int) now()->format('G');
                $greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
                $firstName = \Illuminate\Support\Str::of($u->name)->explode(' ')->first();
            @endphp
            <div class="dropdown">
                {{-- Account pill (Modification V3 item 2): photo with status ring, greeting, name and role --}}
                <button class="account-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu for {{ $u->name }}">
                    <span class="account-avatar-wrap"><x-user-avatar :user="$u" class="account-avatar" /><span class="account-online" title="Signed in"></span></span>
                    <span class="account-text d-none d-md-block">
                        <span class="g d-none d-xl-block">{{ $greet }},</span>
                        <span class="n">{{ $u->name }}</span>
                        <span class="r">{{ $u->designation_label ?? 'Officer' }} · {{ $u->level->label() }}</span>
                    </span>
                    <i class="bi bi-chevron-down account-caret d-none d-md-inline"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end account-menu">
                    <div class="account-head">
                        <x-user-avatar :user="$u" size="lg" />
                        <div class="min-w-0">
                            <div class="account-hi">{{ $greet }}, {{ $firstName }}</div>
                            <div class="account-name text-truncate">{{ $u->name }}</div>
                            <div class="account-role text-truncate">{{ $u->designation_label ?? 'Officer' }}</div>
                            <span class="account-level"><i class="bi bi-diagram-3"></i>{{ $u->level->label() }} level</span>
                        </div>
                    </div>
                    <div class="account-meta">
                        <div><i class="bi bi-geo-alt"></i><span class="text-truncate">{{ $u->currentJurisdiction?->label() ?: 'State' }}</span></div>
                        <div><i class="bi bi-phone"></i><span>+91 {{ $u->mobile }}</span></div>
                    </div>
                    <div class="account-links">
                        <a class="account-link" href="{{ route('profile') }}"><span class="al-icon"><i class="bi bi-person-circle"></i></span><span><b>My Profile</b><small>Photo, details and postings</small></span></a>
                        <a class="account-link" href="{{ route('password.change') }}"><span class="al-icon"><i class="bi bi-key"></i></span><span><b>Change Password</b><small>Keep your account secure</small></span></a>
                        <form method="POST" action="{{ route('logout') }}">@csrf
                            <button class="account-link is-danger"><span class="al-icon"><i class="bi bi-box-arrow-right"></i></span><span><b>Sign out</b><small>End this session</small></span></button></form>
                    </div>
                    <div class="account-foot"><i class="bi bi-clock-history"></i>Signed in {{ $u->last_login_at?->format('d M Y, h:i A') ?? '—' }}</div>
                </div>
            </div>
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
