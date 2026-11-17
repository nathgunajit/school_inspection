@foreach (['success' => 'success', 'status' => 'success', 'warning' => 'warning', 'error' => 'danger', 'info' => 'info'] as $key => $type)
    @if (session($key))
        <div class="alert alert-{{ $type }} border-0 d-flex gap-2 align-items-start" role="alert">
            <i class="bi bi-{{ ['success' => 'check-circle-fill', 'warning' => 'exclamation-triangle-fill', 'danger' => 'exclamation-octagon-fill', 'info' => 'info-circle-fill'][$type] }} mt-1"></i>
            <div>{{ session($key) }}</div>
        </div>
    @endif
@endforeach
