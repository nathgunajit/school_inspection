@props(['group'])
{{-- 25-11-2026: pages merged into one sidebar item (Schools, Inspections, Reports) switch through these tabs. Each tab keeps its own permission. --}}
@php
    $u = auth()->user();
    $groups = [
        'schools' => [
            ['Find School', 'schools.index', 'bi-search', ['schools.index', 'schools.show', 'schools.blank-form'], 'conduct_inspections'],
            ['My Schools', 'schools.mine', 'bi-buildings', 'schools.mine*', 'conduct_inspections'],
        ],
        'inspections' => [
            ['My Inspections', 'inspections.mine', 'bi-clipboard-check', 'inspections.mine', 'conduct_inspections'],
            ['In My Area', 'inspections.area', 'bi-list-check', 'inspections.area', 'view_reports'],
        ],
        'reports' => [
            ['Coverage', 'reports.coverage', 'bi-pie-chart', 'reports.coverage*', 'view_reports'],
            ['Rating', 'reports.ratings', 'bi-bar-chart-line', 'reports.ratings*', 'state_admin'],
            ['Network Issues', 'reports.network*', 'bi-wifi-off', 'reports.network*', 'view_reports'],
        ],
    ];
    $tabs = array_values(array_filter($groups[$group] ?? [], fn ($t) => $u?->can($t[4])));
@endphp
@if (count($tabs) > 1)
    <ul class="nav nav-pills report-tabs page-tabs mb-3" aria-label="Sections">
        @foreach ($tabs as [$label, $route, $icon, $pattern])
            @php $on = request()->routeIs($pattern); $route = rtrim($route, '*'); @endphp
            <li class="nav-item"><a class="nav-link {{ $on ? 'active' : '' }}" @if ($on) aria-current="page" @endif href="{{ route($route) }}"><i class="bi {{ $icon }}"></i>{{ $label }}</a></li>
        @endforeach
    </ul>
@endif
