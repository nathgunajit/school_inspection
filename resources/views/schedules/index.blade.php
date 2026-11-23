<x-layouts.app title="Inspection Schedules">
    <x-slot:actions>
        @if ($pendingCount)
            <a href="{{ route('schedules.approvals') }}" class="btn btn-soft-primary"><i class="bi bi-check2-square"></i><span class="d-none d-sm-inline">Approvals</span> <span class="badge rounded-pill text-bg-warning">{{ $pendingCount }}</span></a>
        @endif
        <a href="{{ route('schedules.plan', ['date' => today()->addDay()->toDateString()]) }}" class="btn btn-primary"><i class="bi bi-calendar-plus"></i><span class="d-none d-sm-inline">Plan a day</span></a>
    </x-slot:actions>

    @php
        $shift = ['day' => 'addDay', 'week' => 'addWeek', 'month' => 'addMonthNoOverflow'][$view];
        $back = ['day' => 'subDay', 'week' => 'subWeek', 'month' => 'subMonthNoOverflow'][$view];
        $q = fn ($over) => route('schedules.index', array_merge(request()->only('officer', 'status'), ['view' => $view, 'date' => $date->toDateString()], $over));
        $title = match ($view) {
            'day' => $date->format('l, d M Y'),
            'week' => $from->format('d M').' – '.$to->format('d M Y'),
            'month' => $date->format('F Y'),
        };
    @endphp

    <div class="filter-bar mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="view" value="{{ $view }}">
            <div class="col-12 col-md-auto">
                <div class="btn-group w-100" role="group" aria-label="Calendar view">
                    @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $k => $l)
                        <a href="{{ $q(['view' => $k]) }}" class="btn {{ $view === $k ? 'btn-primary' : 'btn-light' }}">{{ $l }}</a>
                    @endforeach
                </div>
            </div>
            <div class="col-12 col-md-auto d-flex gap-1 align-items-center">
                <a href="{{ $q(['date' => $date->copy()->$back()->toDateString()]) }}" class="btn btn-light btn-icon" aria-label="Previous"><i class="bi bi-chevron-left"></i></a>
                <input type="date" name="date" value="{{ $date->toDateString() }}" class="form-control" data-autosubmit aria-label="Date">
                <a href="{{ $q(['date' => $date->copy()->$shift()->toDateString()]) }}" class="btn btn-light btn-icon" aria-label="Next"><i class="bi bi-chevron-right"></i></a>
                <a href="{{ $q(['date' => today()->toDateString()]) }}" class="btn btn-light">Today</a>
            </div>
            @if ($officers->count() > 1)
                <div class="col-6 col-md-3"><label class="form-label text-xs mb-1">Officer</label>
                    <select name="officer" class="form-select" data-autosubmit><option value="">All officers</option>
                        @foreach ($officers as $o)<option value="{{ $o->id }}" @selected($officerId === $o->id)>{{ $o->name }} · {{ $o->level->label() }}</option>@endforeach
                    </select></div>
            @endif
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1">Status</label>
                <select name="status" class="form-select" data-autosubmit><option value="">All</option>
                    @foreach (\App\Enums\ScheduleStatus::cases() as $s)<option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>@endforeach
                </select></div>
            <div class="col-12 col-md-auto"><x-filter-buttons :reset="route('schedules.index', ['view' => $view])" /></div>
        </form>
    </div>

    <h2 class="h6 mb-2">{{ $title }}</h2>

    @php
        $card = function ($p) {
            $n = $p->items->count();
            $done = $p->items->where('status', 'inspected')->count();

            return '<a href="'.route('schedules.show', $p).'" class="sched-chip sched-'.$p->status->value.'">'
                .'<span class="fw-semibold text-truncate">'.e($p->officer->name).'</span>'
                .'<span class="text-xs">'.$n.' school'.($n === 1 ? '' : 's').($done ? ' · '.$done.' done' : '').' · '.e($p->status->label()).'</span></a>';
        };
    @endphp

    @if ($view === 'day')
        @php $dayPlans = $plans->get($date->toDateString(), collect()); @endphp
        @forelse ($dayPlans as $p)
            <div class="card mb-2">
                <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                    <div class="flex-grow-1 min-w-0">
                        <a href="{{ route('schedules.show', $p) }}" class="fw-semibold">{{ $p->officer->name }}</a>
                        <div class="text-xs text-muted">{{ $p->items->count() }} schools · {{ number_format($p->total_distance_km, 1) }} km · about {{ intdiv($p->total_minutes, 60) }} h {{ $p->total_minutes % 60 }} min</div>
                        <div class="text-xs mt-1">{{ $p->items->map(fn ($i) => $i->sequence.'. '.$i->school->name)->implode(' → ') }}</div>
                    </div>
                    <x-status-badge :status="$p->status" />
                </div>
            </div>
        @empty
            <x-empty-state icon="bi-calendar-x" title="No plans for this day" text="Use “Plan a day” to choose schools on the map." />
        @endforelse
    @else
        <div class="sched-grid {{ $view }}">
            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)<div class="sched-head d-none d-md-block">{{ $d }}</div>@endforeach
            @for ($d = $from->copy(); $d->lte($to); $d->addDay())
                @php $key = $d->toDateString(); $out = $view === 'month' && ! $d->isSameMonth($date); @endphp
                <div class="sched-day {{ $d->isToday() ? 'is-today' : '' }} {{ $out ? 'is-out' : '' }}">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="sched-date"><span class="d-md-none">{{ $d->format('D') }} </span>{{ $d->format($view === 'month' ? 'j' : 'd M') }}</span>
                        @if ($d->gte(today()))
                            <a href="{{ route('schedules.plan', ['date' => $key] + ($officerId ? ['officer' => $officerId] : [])) }}" class="sched-add" title="Plan this day" aria-label="Plan {{ $d->format('d M') }}"><i class="bi bi-plus-lg"></i></a>
                        @endif
                    </div>
                    @foreach ($plans->get($key, collect()) as $p){!! $card($p) !!}@endforeach
                </div>
            @endfor
        </div>
    @endif

    <div class="d-flex flex-wrap gap-2 mt-3 text-xs">
        @foreach (\App\Enums\ScheduleStatus::cases() as $s)<span class="sched-chip sched-{{ $s->value }} d-inline-flex px-2 py-1">{{ $s->label() }}</span>@endforeach
    </div>
</x-layouts.app>
