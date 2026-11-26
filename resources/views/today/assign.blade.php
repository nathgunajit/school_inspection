<x-layouts.app title="Assign schools" :back="route('today.index')">
    <div class="filter-bar mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-4"><label class="form-label text-xs mb-1" for="a-officer">Officer</label>
                <select id="a-officer" name="officer" class="form-select" data-autosubmit>
                    <option value="">Pick an officer</option>
                    @foreach ($officers as $o)<option value="{{ $o->id }}" @selected($officer?->id === $o->id)>{{ $o->name }} · {{ $o->level->label() }} · {{ $o->currentJurisdiction?->cluster?->label ?? $o->currentJurisdiction?->block?->label ?? $o->currentJurisdiction?->district?->label }}</option>@endforeach
                </select></div>
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="a-date">Date</label>
                <input id="a-date" type="date" name="date" value="{{ $date->toDateString() }}" min="{{ today()->toDateString() }}" class="form-control" data-autosubmit></div>
            <div class="col-6 col-md"><label class="form-label text-xs mb-1" for="a-q">School</label>
                <input id="a-q" name="q" value="{{ request('q') }}" class="form-control" placeholder="Name or UDISE"></div>
            <div class="col-12 col-md-auto"><x-filter-buttons :reset="route('today.assign')" /></div>
        </form>
    </div>

    @if (! $officer)
        <x-empty-state icon="bi-person-plus" title="Pick an officer" text="Choose the officer (for example a CRCC) and the day, then tick the schools to put on their Today's schools list." />
    @else
        @php
            $locked = $marker && $marker->locked_at && ! $marker->unlocked_at; // V15 items 10–11
            $free = $candidates->reject(fn ($c) => $taken->has($c['id']) || $rows->contains('school_id', $c['id']));
        @endphp
        @foreach (['locked', 'school_id', 'unlock_reason'] as $k)@error($k)<div class="alert alert-danger py-2 text-sm">{{ $message }}</div>@enderror @endforeach
        @if ($locked)
            <div class="alert alert-warning text-sm today-lock"><i class="bi bi-lock-fill"></i><div class="flex-grow-1">
                <b>{{ $officer->name }}'s list is locked</b> since {{ \Carbon\Carbon::parse($marker->locked_at)->format('h:i A') }}: an inspection has started. Schools cannot be added, removed or replaced@if (! auth()->user()->isStateAdmin()) unless the State Administrator allows changes@endif.
                @if (auth()->user()->isStateAdmin())
                    <form method="POST" action="{{ route('today.unlock') }}" class="d-flex flex-wrap gap-2 mt-2">@csrf
                        <input type="hidden" name="officer_id" value="{{ $officer->id }}"><input type="hidden" name="date" value="{{ $date->toDateString() }}">
                        <input name="unlock_reason" class="form-control form-control-sm flex-grow-1" style="max-width: 420px" minlength="5" maxlength="200" required placeholder="Why must the list change? (recorded)" value="{{ old('unlock_reason') }}">
                        <button class="btn btn-sm btn-warning"><i class="bi bi-unlock"></i>Allow changes</button>
                    </form>
                @endif
            </div></div>
        @elseif ($marker && $marker->unlocked_at)
            <div class="alert alert-light border text-sm today-lock"><i class="bi bi-unlock"></i><span>Changes allowed by the State Administrator: {{ $marker->unlock_reason }}. The list locks again when the next inspection starts.</span></div>
        @endif
        @if ($off)<div class="alert alert-warning text-sm"><i class="bi bi-calendar-heart me-1"></i>{{ $date->format('d M Y') }} is not a working day for this officer ({{ $off }}). Pick another date.</div>@endif
        <div class="row g-4">
            <div class="col-lg-7">
                <form method="POST" action="{{ route('today.assign.store') }}" class="card">
                    @csrf
                    <input type="hidden" name="officer_id" value="{{ $officer->id }}"><input type="hidden" name="date" value="{{ $date->toDateString() }}">
                    <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-buildings me-2 text-primary"></i>Schools in {{ $officer->name }}'s area</span><span class="badge text-bg-light">{{ $candidates->count() }}</span></div>
                    @error('school_ids')<div class="alert alert-danger m-2 py-2 text-sm">{{ $message }}</div>@enderror
                    @error('date')<div class="alert alert-danger m-2 py-2 text-sm">{{ $message }}</div>@enderror
                    <div class="list-group list-group-flush assign-list">
                        @forelse ($candidates as $c)
                            @php $other = $taken->get($c['id']); $mine = $rows->contains('school_id', $c['id']); @endphp
                            <label class="list-group-item d-flex gap-2 align-items-start {{ $other || $mine ? 'text-secondary' : '' }}">
                                <input type="checkbox" class="form-check-input mt-1" name="school_ids[]" value="{{ $c['id'] }}" @disabled($other || $mine || $off || $locked)>
                                <span class="flex-grow-1 min-w-0"><span class="d-block fw-semibold text-truncate">{{ $c['name'] }}</span>
                                    <span class="d-block text-xs">UDISE {{ $c['udise'] }}@if ($c['cluster']) · {{ $c['cluster'] }}@endif · {{ $c['last'] ? 'last inspected '.$c['last'] : 'not inspected yet' }}</span>
                                    @if ($mine)<span class="d-block text-xs text-success">Already on this list</span>
                                    @elseif ($other)<span class="d-block text-xs text-warning">On {{ $other->officer->name }}'s list that day</span>
                                    @elseif ($c['blocked'])<span class="d-block text-xs text-warning">{{ ucfirst($c['blocked']) }}</span>@endif</span>
                            </label>
                        @empty
                            <div class="dash-empty"><i class="bi bi-search"></i>No active schools found.</div>
                        @endforelse
                    </div>
                    <div class="card-footer"><button class="btn btn-primary" @disabled($off || $locked)><i class="bi bi-plus-lg"></i>Assign ticked schools</button></div>
                </form>
            </div>
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><i class="bi bi-signpost-2 me-2 text-primary"></i>{{ $officer->name }} · {{ $date->format('d M Y') }}</div>
                    @forelse ($rows as $r)
                        <div class="list-group-item d-flex gap-2 align-items-center px-3 py-2 border-bottom">
                            <span class="flex-grow-1 min-w-0"><span class="d-block text-sm fw-semibold text-truncate">{{ $r->school->name }}</span>
                                <span class="d-block text-xs text-secondary">{{ $r->reasonLabel() }} · {{ ucfirst($r->status) }}@if ($r->assigner) · by {{ $r->assigner->name }}@endif</span></span>
                            {{-- V15 item 9: any pending school can be removed or replaced until its inspection starts --}}
                            @if ($r->status === 'pending' && $drafts->has($r->school_id))
                                <span class="badge-soft badge-soft-warning text-xs">Started</span>
                            @elseif ($r->status === 'pending' && ! $locked)
                                <button type="button" class="btn btn-sm btn-light btn-icon" data-bs-toggle="modal" data-bs-target="#replace{{ $r->id }}" aria-label="Replace {{ $r->school->name }}" title="Replace"><i class="bi bi-arrow-left-right"></i></button>
                                <form method="POST" action="{{ route('today.remove', $r) }}" data-confirm="Remove {{ $r->school->name }} from the list?">@csrf @method('DELETE')
                                    <button class="btn btn-sm btn-light btn-icon text-danger" aria-label="Remove {{ $r->school->name }}" title="Remove"><i class="bi bi-x-lg"></i></button></form>
                            @endif
                        </div>
                    @empty
                        <div class="dash-empty"><i class="bi bi-inbox"></i>Nothing on this day's list yet. The automatic schools are added when the officer opens the list that morning.</div>
                    @endforelse
                </div>
            </div>
        </div>
        @unless ($locked)
            @foreach ($rows->where('status', 'pending')->reject(fn ($r) => $drafts->has($r->school_id)) as $r)
                <div class="modal fade" id="replace{{ $r->id }}" tabindex="-1" aria-labelledby="replace{{ $r->id }}t" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered"><form method="POST" action="{{ route('today.replace', $r) }}" class="modal-content">@csrf
                        <div class="modal-header"><h5 class="modal-title" id="replace{{ $r->id }}t">Replace {{ $r->school->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                        <div class="modal-body">
                            <label class="form-label" for="rs{{ $r->id }}">School to put in its place</label>
                            <select id="rs{{ $r->id }}" name="school_id" class="form-select" required>
                                <option value="">Pick a school</option>
                                @foreach ($free as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }} · {{ $c['udise'] }}</option>@endforeach
                            </select>
                            <div class="form-text">Only schools of {{ $officer->name }}'s area that are on no list that day. Use the School search above to narrow the list.</div>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-arrow-left-right"></i>Replace</button></div>
                    </form></div>
                </div>
            @endforeach
        @endunless
    @endif
</x-layouts.app>
