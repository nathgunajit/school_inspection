@php
    $rows = $list['rows'];
    $done = $rows->where('status', 'visited')->count();
    $countable = $rows->whereIn('status', ['pending', 'visited', 'skipped'])->count();
    $isToday = $date->isToday();
@endphp
<x-layouts.app title="Today's Schools">
    <x-slot:actions>
        @if ($canAssign)<a href="{{ route('today.assign') }}" class="btn btn-soft-primary"><i class="bi bi-person-plus"></i><span class="d-none d-sm-inline">Assign schools</span></a>@endif
        @can('manage_holidays')<a href="{{ route('today.settings') }}" class="btn btn-light"><i class="bi bi-sliders"></i><span class="d-none d-sm-inline">Settings</span></a>@endcan
    </x-slot:actions>

    <div class="filter-bar mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-auto d-flex gap-1 align-items-center">
                <a href="{{ route('today.index', ['date' => $date->copy()->subDay()->toDateString()]) }}" class="btn btn-light btn-icon" aria-label="Previous day"><i class="bi bi-chevron-left"></i></a>
                <input type="date" name="date" value="{{ $date->toDateString() }}" max="{{ today()->toDateString() }}" min="{{ today()->subDays(30)->toDateString() }}" class="form-control" data-autosubmit aria-label="Date">
                @unless ($isToday)<a href="{{ route('today.index', ['date' => $date->copy()->addDay()->toDateString()]) }}" class="btn btn-light btn-icon" aria-label="Next day"><i class="bi bi-chevron-right"></i></a>
                <a href="{{ route('today.index') }}" class="btn btn-light">Today</a>@endunless
            </div>
            <div class="col text-sm text-secondary">
                <b class="text-body">{{ $date->format('l, d M Y') }}</b>
                @if ($start['label'])<span class="d-block text-xs"><i class="bi bi-geo me-1"></i>Distances from {{ $start['label'] }}</span>@endif
            </div>
        </form>
    </div>

    @if ($list['state'] === 'off')
        <div class="today-off"><i class="bi bi-calendar-heart"></i><div><b>{{ $list['reason'] }}</b><span>No schools are listed on this day.</span></div></div>
    @elseif ($list['state'] === 'disabled' && $rows->isEmpty())
        <x-empty-state icon="bi-signpost-2" title="Today's schools is not switched on for you" text="The administrator decides which officers get a daily list of schools. You can still plan your own visits in Inspection Schedules." />
    @elseif ($rows->isEmpty())
        <x-empty-state icon="bi-check2-all" :title="$isToday ? 'No schools for today' : 'No schools were listed on this day'" text="Every eligible school in your area was visited recently, or is on another officer's list. Use Find School to start any inspection." />
    @endif

    @if (! empty($list['refreshed']))
        <div class="alert alert-info py-2 text-sm"><i class="bi bi-info-circle me-1"></i>List updated after a change in the Today's Schools settings.</div>
    @endif

    @if ($rows->isNotEmpty())
        <div class="dash-panel">
            <div class="dash-panel-head">
                <h3><i class="bi bi-signpost-2"></i>{{ $isToday ? "Today's schools" : 'Schools on '.$date->format('d M') }} · {{ $done }} of {{ $countable }} visited</h3>
                <span class="text-xs text-secondary">Daily limit {{ $setting->daily_limit }}@if ($rows->contains('source', 'carried')) + pending from earlier @endif</span>
            </div>
            @foreach ($rows as $r)
                @php $s = $r->school; $km = $distance($s); $draft = $r->status === 'pending' ? $drafts->get($r->school_id) : null; @endphp
                <div class="today-row is-{{ $r->status }} {{ $draft ? 'is-draft' : '' }}">
                    <span class="plan-no {{ $r->status === 'visited' ? 'is-done' : (in_array($r->status, ['skipped', 'carried'], true) ? 'is-skipped' : '') }}">{{ $loop->iteration }}</span>
                    <div class="flex-grow-1 min-w-0">
                        <a href="{{ route('schools.show', $s) }}" class="fw-semibold text-body d-block text-truncate">{{ $s->name }}</a>
                        <div class="text-xs text-secondary">UDISE {{ $s->udise_code }}@if ($s->cluster) · {{ $s->cluster->label }}@endif @if ($km !== null) · {{ number_format($km, 1) }} km away @elseif ($s->latitude === null) · no GPS @endif</div>
                        <div class="d-flex flex-wrap gap-1 mt-1 align-items-center">
                            <span class="today-reason is-{{ $r->reason }}">{{ $r->reasonLabel() }}</span>
                            @if ($r->carried_from)<span class="text-xs text-secondary">since {{ \Carbon\Carbon::parse($r->carried_from)->format('d M') }}</span>@endif
                            @if ($r->assigner)<span class="text-xs text-secondary">by {{ $r->assigner->name }}</span>@endif
                            @if ($draft)
                                {{-- 25-11-2026: started but not submitted: stays on the list until it is submitted --}}
                                <span class="badge-soft badge-soft-warning"><i class="bi bi-pencil-square me-1"></i>Draft in progress · {{ (int) $draft->completion_pct }}%</span>
                                <span class="text-xs text-secondary">last saved {{ $draft->updated_at->format('d M, h:i A') }}</span>
                            @elseif ($r->status === 'visited')
                                <span class="badge-soft badge-soft-success">Visited</span>
                                @if ($r->inspection)<a href="{{ route('inspections.show', $r->inspection) }}" class="text-xs">{{ $r->inspection->inspection_no }}</a>@endif
                            @elseif ($r->status === 'skipped')
                                <span class="badge-soft badge-soft-secondary" title="{{ $r->skip_reason }}">Not visited · {{ \Illuminate\Support\Str::limit($r->skip_reason, 40) }}</span>
                            @elseif ($r->status === 'carried')
                                <span class="badge-soft badge-soft-warning">Moved to the next working day</span>
                            @endif
                        </div>
                    </div>
                    @if ($r->status === 'pending')
                        <div class="today-actions">
                            @if ($s->latitude !== null && $s->longitude !== null)
                                <a href="https://www.google.com/maps/dir/?api=1&destination={{ $s->latitude }},{{ $s->longitude }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-light" data-no-loader><i class="bi bi-sign-turn-right"></i>Route</a>
                            @endif
                            @if ($draft)
                                <a href="{{ route('inspections.step', [$draft, 1]) }}" class="btn btn-sm btn-warning"><i class="bi bi-pencil-square"></i>Continue</a>
                            @elseif ($isToday)
                                <form method="POST" action="{{ route('inspections.start', $s) }}" data-geo-start>@csrf
                                    <input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="accuracy">
                                    <button class="btn btn-sm btn-primary"><i class="bi bi-play-fill"></i>Start</button></form>
                            @endif
                            @unless ($draft)<button type="button" class="btn btn-sm btn-light" data-bs-toggle="collapse" data-bs-target="#skip{{ $r->id }}" aria-label="Not visited">Skip</button>@endunless
                        </div>
                    @endif
                </div>
                @if ($r->status === 'pending' && ! $draft)
                    <form method="POST" action="{{ route('today.skip', $r) }}" class="collapse today-skip" id="skip{{ $r->id }}">@csrf
                        <div class="input-group input-group-sm"><input name="reason" class="form-control" maxlength="200" placeholder="Why can the school not be visited?" required>
                            <button class="btn btn-outline-secondary">Save</button></div>
                    </form>
                @endif
            @endforeach
        </div>
        @if ($setting->carry_forward)<p class="text-xs text-secondary mt-2"><i class="bi bi-info-circle me-1"></i>Schools not visited move to your next working day's list. A started inspection stays on the list as a draft until it is submitted, which marks the school as visited.</p>@endif
    @endif
</x-layouts.app>
