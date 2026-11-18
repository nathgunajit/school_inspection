{{-- $steps, $progress, $current (int|'review'), $inspection --}}
@php
    // Status line under each tab: Done / In progress · n% / Not started
    $status = function (int $n) use ($progress) {
        $st = $progress['steps'][$n] ?? ['complete' => false, 'pct' => 0];
        return $st['complete'] ? ['Done', 'is-done'] : (($st['pct'] ?? 0) > 0 ? ['In progress · '.$st['pct'].'%', 'is-partial'] : ['Not started', '']);
    };
    $doneCount = collect($progress['steps'] ?? [])->where('complete', true)->count();
@endphp
<div class="stepper">
    <div class="stepper-top">
        <div class="flex-grow-1 min-w-0">
            <div class="stepper-school text-truncate">{{ $inspection->school->name }}</div>
            <div class="stepper-meta"><span class="font-monospace">{{ $inspection->school->udise_code }}</span> · {{ $inspection->inspection_date->format('d M Y') }}
                @if ($inspection->status->value === 'unlocked')<span class="badge-soft badge-soft-info ms-1">Unlocked for correction</span>@endif</div>
        </div>
        <div class="stepper-progress">
            <span class="save-indicator" id="save-indicator"><i class="bi bi-cloud-check"></i>Saved</span>
            <span class="stepper-count"><span id="steps-done">{{ $doneCount }}</span> of {{ count($steps) }} steps done</span>
            <span class="stepper-pct" id="overall-pct">{{ $progress['pct'] }}%</span>
        </div>
    </div>
    <div class="progress-thin progress mb-2"><div class="progress-bar" id="overall-bar" style="width: {{ $progress['pct'] }}%"></div></div>
    <nav class="stepper-track" aria-label="Inspection steps">
        @foreach ($steps as $n => $s)
            @php $done = $progress['steps'][$n]['complete'] ?? false; [$stText, $stClass] = $status($n); @endphp
            <a href="{{ route('inspections.step', [$inspection, $n]) }}" data-step-link="{{ $n }}" title="Step {{ $n }}: {{ $s['title'] }}"
               class="stepper-item text-decoration-none {{ $current === $n ? 'active' : '' }} {{ $done ? 'complete' : '' }}" @if ($current === $n) aria-current="step" @endif>
                <span class="dot">@if ($done && $current !== $n)<i class="bi bi-check-lg"></i>@else{{ $n }}@endif</span>
                <span class="txt">
                    <span class="lbl">{{ $s['tab'] ?? $s['short'] }}</span>
                    <span class="st {{ $stClass }}" data-step-status>{{ $current === $n ? 'You are here' : $stText }}</span>
                </span>
            </a>
        @endforeach
        <a href="{{ route('inspections.review', $inspection) }}" title="Check all answers and submit" class="stepper-item is-review text-decoration-none {{ $current === 'review' ? 'active' : '' }}" @if ($current === 'review') aria-current="step" @endif>
            <span class="dot"><i class="bi bi-flag"></i></span>
            <span class="txt">
                <span class="lbl">Review &amp; Submit</span>
                <span class="st">{{ $current === 'review' ? 'You are here' : ($progress['can_submit'] ? 'Ready to submit' : 'Check & submit') }}</span>
            </span>
        </a>
    </nav>
</div>
