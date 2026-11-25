{{-- 25-11-2026: dashboard calendar card (right rail). $calendar from DashboardController (month, byDate, upcoming, weeklyOff). --}}
@php
    $m = $calendar['month'];
    $from = $m->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
    $to = $m->copy()->endOfMonth()->endOfWeek(\Carbon\Carbon::SUNDAY);
@endphp
<div class="dash-panel tone-danger">
    <div class="dash-panel-head">
        <h3><i class="bi bi-calendar3"></i>{{ $m->format('F Y') }}</h3>
        <a href="{{ route('holidays.index') }}">Holiday Calendar <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="mini-cal" role="grid" aria-label="{{ $m->format('F Y') }}">
        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)<div class="mini-cal-head">{{ $d }}</div>@endforeach
        @for ($d = $from->copy(); $d->lte($to); $d->addDay())
            @php
                $list = $calendar['byDate']->get($d->toDateString(), collect());
                $out = ! $d->isSameMonth($m);
                $types = $list->pluck('type')->unique()->values();
                $tip = $list->map(fn ($h) => $h->name.($h->isOff() ? '' : ' ('.strtolower($h->typeLabel()).')'))->implode(', ');
            @endphp
            <div class="mini-cal-day {{ $out ? 'is-out' : '' }} {{ $d->isToday() ? 'is-today' : '' }} {{ $list->contains(fn ($h) => $h->isOff()) ? 'is-holiday' : '' }} {{ in_array((int) $d->dayOfWeek, $calendar['weeklyOff'], true) ? 'is-off' : '' }}"
                @if ($tip && ! $out) title="{{ $d->format('d M') }}: {{ $tip }}" @endif>
                {{ $d->format('j') }}
                @if (! $out && $types->isNotEmpty())<span class="mini-dots">@foreach ($types as $t)<span class="mini-dot is-{{ $t }}"></span>@endforeach</span>@endif
            </div>
        @endfor
    </div>
    <div class="mini-cal-legend">
        <span><span class="mini-dot is-gazetted"></span>Holiday</span>
        <span><span class="mini-dot is-restricted"></span>Restricted</span>
        <span><span class="mini-dot is-half"></span>Half day</span>
    </div>

    <div class="dash-subhead">Upcoming holidays</div>
    @forelse ($calendar['upcoming'] as $h)
        @php
            $hd = \Carbon\Carbon::parse($h->date);
            $days = (int) today()->diffInDays($hd, false);
            $when = $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : 'in '.$days.' days');
        @endphp
        <div class="dash-row">
            <span class="holiday-date"><b>{{ $hd->format('d') }}</b>{{ $hd->format('M') }}</span>
            <span class="flex-grow-1 min-w-0">
                <span class="d-block fw-semibold text-sm text-truncate text-body" title="{{ $h->name }}">{{ $h->name }}</span>
                <span class="d-block text-xs text-secondary">{{ $hd->format('l') }} · {{ $when }}</span>
            </span>
            <span class="holiday-chip {{ $h->chipClass() }} d-inline-block text-nowrap">{{ $h->isOff() ? ($h->isState() ? 'Holiday' : 'Local') : ($h->type === 'half' ? 'Half day' : 'Restricted') }}</span>
        </div>
    @empty
        <div class="dash-empty"><i class="bi bi-calendar-x"></i>No holidays added for the coming days.</div>
    @endforelse
</div>
