@props(['status'])
@php
    $tone = method_exists($status, 'badge') ? $status->badge() : 'secondary';
    $label = method_exists($status, 'label') ? $status->label() : ucfirst((string) $status);
@endphp
<span {{ $attributes->merge(['class' => "badge-soft badge-soft-$tone"]) }}>{{ $label }}</span>
