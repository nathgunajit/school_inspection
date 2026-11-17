{{-- $steps, $progress, $current (int|'review'), $inspection --}}
<div class="stepper">
    <div class="d-flex align-items-center gap-3 mb-2">
        <div class="flex-grow-1 min-w-0">
            <div class="fw-semibold text-truncate">{{ $inspection->school->name }}</div>
            <div class="text-xs text-secondary"><span class="font-monospace">{{ $inspection->school->udise_code }}</span> · {{ $inspection->inspection_date->format('d M Y') }}
                @if ($inspection->status->value === 'unlocked')<span class="badge-soft badge-soft-info ms-1">Unlocked for correction</span>@endif</div>
        </div>
        <div class="text-end">
            <div class="fw-bold text-primary" id="overall-pct">{{ $progress['pct'] }}%</div>
            <span class="save-indicator" id="save-indicator"><i class="bi bi-cloud-check"></i>Saved</span>
        </div>
    </div>
    <div class="progress-thin progress mb-2"><div class="progress-bar" id="overall-bar" style="width: {{ $progress['pct'] }}%"></div></div>
    <div class="stepper-track" role="tablist" aria-label="Inspection steps">
        @foreach ($steps as $n => $s)
            @php $done = $progress['steps'][$n]['complete'] ?? false; @endphp
            <a href="{{ route('inspections.step', [$inspection, $n]) }}" data-step-link="{{ $n }}"
               class="stepper-item text-decoration-none {{ $current === $n ? 'active' : '' }} {{ $done ? 'complete' : '' }}" @if ($current === $n) aria-current="step" @endif>
                <span class="dot">@if ($done && $current !== $n)<i class="bi bi-check-lg"></i>@else{{ $n }}@endif</span>{{ $s['short'] }}
            </a>
        @endforeach
        <a href="{{ route('inspections.review', $inspection) }}" class="stepper-item text-decoration-none {{ $current === 'review' ? 'active' : '' }}">
            <span class="dot"><i class="bi bi-flag"></i></span>Review
        </a>
    </div>
</div>
