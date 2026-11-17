{{-- $steps, $progress, $current (int|'review'), $inspection --}}
<div class="stepper">
    <div class="stepper-top">
        <div class="flex-grow-1 min-w-0">
            <div class="stepper-school text-truncate">{{ $inspection->school->name }}</div>
            <div class="stepper-meta"><span class="font-monospace">{{ $inspection->school->udise_code }}</span> · {{ $inspection->inspection_date->format('d M Y') }}
                @if ($inspection->status->value === 'unlocked')<span class="badge-soft badge-soft-info ms-1">Unlocked for correction</span>@endif</div>
        </div>
        <div class="stepper-progress">
            <span class="save-indicator" id="save-indicator"><i class="bi bi-cloud-check"></i>Saved</span>
            <span class="stepper-pct" id="overall-pct">{{ $progress['pct'] }}%</span>
        </div>
    </div>
    <div class="progress-thin progress mb-2"><div class="progress-bar" id="overall-bar" style="width: {{ $progress['pct'] }}%"></div></div>
    <div class="stepper-track" role="tablist" aria-label="Inspection steps">
        @foreach ($steps as $n => $s)
            @php $done = $progress['steps'][$n]['complete'] ?? false; @endphp
            <a href="{{ route('inspections.step', [$inspection, $n]) }}" data-step-link="{{ $n }}" title="{{ $s['title'] }}"
               class="stepper-item text-decoration-none {{ $current === $n ? 'active' : '' }} {{ $done ? 'complete' : '' }}" @if ($current === $n) aria-current="step" @endif>
                <span class="dot">@if ($done && $current !== $n)<i class="bi bi-check-lg"></i>@else{{ $n }}@endif</span><span class="lbl">{{ $s['short'] }}</span>
            </a>
        @endforeach
        <a href="{{ route('inspections.review', $inspection) }}" title="Review &amp; submit" class="stepper-item text-decoration-none {{ $current === 'review' ? 'active' : '' }}">
            <span class="dot"><i class="bi bi-flag"></i></span><span class="lbl">Review</span>
        </a>
    </div>
</div>
