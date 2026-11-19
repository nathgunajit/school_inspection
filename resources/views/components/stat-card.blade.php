{{-- wrap (Modification V8): the label may break onto two lines inside the box instead of being cut off; hint: a small line under the label --}}
@props(['label', 'value', 'icon' => 'bi-bar-chart', 'tone' => 'primary', 'href' => null, 'wrap' => false, 'hint' => null])
@php
    $labelClass = $wrap ? 'stat-label stat-label-wrap' : 'stat-label text-truncate';
    $inner = '<div class="card-body d-flex align-items-center gap-3"><div class="stat-icon tone-'.e($tone).'"><i class="bi '.e($icon).'"></i></div>'
        .'<div class="min-w-0"><div class="stat-value">'.e($value).'</div><div class="'.$labelClass.'">'.e($label).'</div>'
        .($hint ? '<div class="stat-hint">'.e($hint).'</div>' : '').'</div></div>';
    $cls = 'card stat-card h-100'.($wrap ? ' stat-card-wrap tone-edge-'.e($tone) : '');
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $cls.' text-decoration-none']) }}>{!! $inner !!}</a>
@else
    <div {{ $attributes->merge(['class' => $cls]) }}>{!! $inner !!}</div>
@endif
