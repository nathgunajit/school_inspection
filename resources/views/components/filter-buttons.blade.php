{{-- 23-11-2026: the Apply / Reset pair of every filter bar, one fixed size everywhere (.btn-filter, app.scss). --}}
@props(['reset' => null, 'label' => 'Apply', 'icon' => 'bi-funnel', 'resetLabel' => 'Reset'])
<div {{ $attributes->merge(['class' => 'filter-actions']) }}>
    <button class="btn btn-primary btn-filter" data-loading-text="Loading…"><i class="bi {{ $icon }}"></i>{{ $label }}</button>
    @if ($reset !== false)
        <a href="{{ $reset ?? url()->current() }}" class="btn btn-light btn-filter-reset" aria-label="Reset filters" title="Reset filters"><i class="bi bi-arrow-counterclockwise"></i><span class="d-none d-md-inline">{{ $resetLabel }}</span></a>
    @endif
</div>
