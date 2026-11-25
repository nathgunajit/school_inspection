{{-- 25-11-2026: dashboard Calendar & Notices. $calendar from DashboardController (month, byDate, upcoming, weeklyOff). --}}
@php
    $m = $calendar['month'];
    $from = $m->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
    $to = $m->copy()->endOfMonth()->endOfWeek(\Carbon\Carbon::SUNDAY);
@endphp
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="dash-panel h-100">
            <div class="dash-panel-head">
                <h3><i class="bi bi-calendar3"></i>Calendar · {{ $m->format('F Y') }}</h3>
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
            <div class="dash-panel-head mt-2"><h3 class="text-sm"><i class="bi bi-calendar-heart"></i>Upcoming holidays</h3></div>
            @forelse ($calendar['upcoming'] as $h)
                <div class="dash-row">
                    <span class="holiday-date"><b>{{ \Carbon\Carbon::parse($h->date)->format('d') }}</b>{{ \Carbon\Carbon::parse($h->date)->format('M') }}</span>
                    <span class="flex-grow-1 min-w-0">
                        <span class="d-block fw-semibold text-sm text-truncate text-body">{{ $h->name }}</span>
                        <span class="d-block text-xs text-secondary">{{ \Carbon\Carbon::parse($h->date)->format('l') }}@if ($h->date === today()->toDateString()) · today @endif</span>
                    </span>
                    <span class="holiday-chip {{ $h->chipClass() }} d-inline-block text-nowrap">{{ $h->isOff() ? ($h->isState() ? 'Holiday' : 'Local') : ($h->type === 'half' ? 'Half day' : 'Restricted') }}</span>
                </div>
            @empty
                <div class="dash-empty"><i class="bi bi-calendar-x"></i>No holidays added for the coming days.</div>
            @endforelse
        </div>
    </div>

    <div class="col-lg-7">
        <div class="dash-panel h-100">
            <div class="dash-panel-head">
                <h3><i class="bi bi-megaphone"></i>{{ $noticesSent ? 'Notices & orders sent' : 'Notices & orders' }}</h3>
                <a href="{{ route('notices.index') }}">View all <i class="bi bi-arrow-right"></i></a>
            </div>
            @forelse ($notices as $n)
                @php $new = ! $noticesSent && ! $n->reads->first()?->read_at; @endphp
                <a href="{{ route('notices.show', $n) }}" class="dash-row">
                    <span class="notice-icon is-{{ $n->type }}"><i class="bi {{ $n->typeIcon() }}"></i></span>
                    <span class="flex-grow-1 min-w-0">
                        <span class="d-block fw-semibold text-sm text-truncate text-body">{{ $n->title }}</span>
                        <span class="d-block text-xs text-secondary text-truncate">{{ $n->typeLabel() }}@if ($n->ref_no) · No. {{ $n->ref_no }}@endif · {{ $noticesSent ? 'read by '.$n->read_count : $n->creator->name }}@if ($n->isWithdrawn()) · withdrawn @endif</span>
                    </span>
                    @if ($new)<span class="badge text-bg-warning">New</span>@endif
                    <span class="text-xs text-secondary text-nowrap">{{ \Carbon\Carbon::parse($n->notice_date)->format('d M') }}</span>
                </a>
            @empty
                <div class="dash-empty"><i class="bi bi-megaphone"></i>{{ $noticesSent ? 'No notices sent yet.' : 'No notices or orders for you.' }}
                    @can('issue_notices')<a href="{{ route('notices.create') }}" class="d-block mt-1">Write a notice</a>@endcan</div>
            @endforelse
        </div>
    </div>
</div>
