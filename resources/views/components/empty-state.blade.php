@props(['icon' => 'bi-inbox', 'title' => 'Nothing here yet', 'text' => null])
<div class="empty-state">
    <div class="empty-icon"><i class="bi {{ $icon }}"></i></div>
    <h3 class="h6 text-dark mb-1">{{ $title }}</h3>
    @if ($text)<p class="text-sm mb-3">{{ $text }}</p>@endif
    {{ $slot }}
</div>
