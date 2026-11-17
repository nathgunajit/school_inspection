@props(['label', 'value', 'icon' => 'bi-bar-chart', 'tone' => 'primary', 'href' => null])
@php $inner = '<div class="card-body d-flex align-items-center gap-3"><div class="stat-icon tone-'.e($tone).'"><i class="bi '.e($icon).'"></i></div><div class="min-w-0"><div class="stat-value">'.e($value).'</div><div class="stat-label text-truncate">'.e($label).'</div></div></div>'; @endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'card stat-card h-100 text-decoration-none']) }}>{!! $inner !!}</a>
@else
    <div {{ $attributes->merge(['class' => 'card stat-card h-100']) }}>{!! $inner !!}</div>
@endif
