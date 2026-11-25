{{-- 25-11-2026: dashboard calendar card (right rail). $calendar from DashboardController (month, byDate, weeklyOff, day popup data). --}}
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
            @php
                $weekOff = in_array((int) $d->dayOfWeek, $calendar['weeklyOff'], true);
                $clickable = ! $out && ($calendar['canManage'] || $list->isNotEmpty()); // 25-11-2026: day popup
            @endphp
            <div class="mini-cal-day {{ $out ? 'is-out' : '' }} {{ $d->isToday() ? 'is-today' : '' }} {{ $list->contains(fn ($h) => $h->isOff()) ? 'is-holiday' : '' }} {{ $weekOff ? 'is-off' : '' }} {{ $clickable ? 'is-clickable' : '' }}"
                @if ($tip && ! $out) title="{{ $d->format('d M') }}: {{ $tip }}" @endif
                @if ($clickable) data-holiday-day="{{ json_encode($calendar['service']->dayData(auth()->user(), $d, $list, $weekOff)) }}" role="button" tabindex="0"
                    aria-label="{{ $d->format('j M') }}: {{ $list->isEmpty() ? 'add a holiday' : ($tip ?: 'holiday') }}" @endif>
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

    {{-- 25-11-2026: the Upcoming holidays list is hidden; every holiday is on the month (click a date) and the Holiday Calendar --}}
</div>
@include('holidays.partials.day-modal', ['canManage' => $calendar['canManage'], 'editableDistricts' => $calendar['editableDistricts'], 'back' => 'dashboard'])
