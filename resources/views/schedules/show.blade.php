@php
    use App\Enums\ScheduleStatus;
    $s = $schedule;
    $open = $s->status->isOpen();
    $canEdit = $superior ? $open : ($own && ($s->status->isEditableByOfficer() || ($s->status === ScheduleStatus::Published && ! config('inspection.schedule.approval_required'))));
    $stops = $s->items->map(fn ($i) => ['n' => $i->sequence, 'name' => $i->school->name, 'lat' => $i->school->latitude, 'lng' => $i->school->longitude, 'status' => $i->status])->values();
    $located = $s->items->filter(fn ($i) => $i->school->hasCoordinates());
    $dir = $located->isNotEmpty()
        ? 'https://www.google.com/maps/dir/?api=1&travelmode=driving'
            .($start ? '&origin='.$start[0].','.$start[1] : '')
            .'&destination='.$located->last()->school->latitude.','.$located->last()->school->longitude
            .($located->count() > 1 ? '&waypoints='.urlencode($located->slice(0, -1)->map(fn ($i) => $i->school->latitude.','.$i->school->longitude)->implode('|')) : '')
        : null;
    $labels = [
        'schedule_created' => 'Plan created', 'schedule_updated' => 'Plan changed', 'schedule_submitted' => 'Submitted for approval',
        'schedule_approved' => 'Approved', 'schedule_rejected' => 'Sent back', 'schedule_published' => 'Published',
        'schedule_cancelled' => 'Cancelled', 'schedule_reassigned' => 'Reassigned', 'schedule_item_inspected' => 'School inspected',
        'schedule_item_skipped' => 'Visit skipped', 'schedule_completed' => 'Plan completed',
    ];
@endphp
<x-layouts.app :title="'Plan · '.$s->schedule_date->format('d M Y')" :back="route('schedules.index', ['date' => $s->schedule_date->toDateString()])">
    <x-slot:actions>
        @if ($canEdit)
            <a href="{{ route('schedules.plan', ['date' => $s->schedule_date->toDateString(), 'officer' => $s->officer_id]) }}" class="btn btn-soft-primary"><i class="bi bi-pencil"></i><span class="d-none d-sm-inline">Change plan</span></a>
        @endif
        @if ($dir)
            <a href="{{ $dir }}" target="_blank" rel="noopener" class="btn btn-primary"><i class="bi bi-sign-turn-right"></i><span class="d-none d-sm-inline">Directions</span></a>
        @endif
    </x-slot:actions>

    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-3 align-items-center">
            <x-user-avatar :user="$s->officer" />
            <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold">{{ $s->officer->name }} <span class="text-muted text-sm">· {{ $s->officer->level->label() }}</span></div>
                <div class="text-sm text-muted">{{ $s->schedule_date->format('l, d M Y') }} · {{ $s->items->count() }} school(s) · ~{{ number_format($s->total_distance_km, 1) }} km · ~{{ intdiv($s->total_minutes, 60) }} h {{ $s->total_minutes % 60 }} min</div>
                @if ($s->notes)<div class="text-sm mt-1"><i class="bi bi-sticky me-1"></i>{{ $s->notes }}</div>@endif
                @if ($s->review_note)
                    <div class="text-sm mt-1 {{ $s->status === ScheduleStatus::Rejected ? 'text-danger' : '' }}"><i class="bi bi-chat-left-text me-1"></i>{{ $s->status === ScheduleStatus::Cancelled ? 'Reason' : 'Note' }}: {{ $s->review_note }}</div>
                @endif
            </div>
            <x-status-badge :status="$s->status" class="fs-6" />
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body p-2">
                    <div id="routeMap" class="si-map route-map" data-config='@json($mapConfig)' data-start='@json($start)'
                         data-stops='@json($stops)'></div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-signpost-split me-1"></i>Visits in order</div>
                <ol class="list-group list-group-flush">
                    @forelse ($s->items as $i)
                        <li class="list-group-item d-flex gap-2 align-items-start">
                            <span class="plan-no {{ $i->status === 'inspected' ? 'is-done' : ($i->status === 'skipped' ? 'is-skipped' : '') }}">{{ $i->sequence }}</span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold text-truncate">{{ $i->school->name }}</div>
                                <div class="text-xs text-muted">{{ $i->school->udise_code }} · arrive {{ $i->planned_arrival }}@if ($i->distance_km !== null) · {{ number_format($i->distance_km, 1) }} km, ~{{ $i->travel_minutes }} min @elseif (! $i->school->hasCoordinates()) · no GPS @endif</div>
                                <div class="mt-1 d-flex flex-wrap gap-1 align-items-center">
                                    @if ($i->status === 'inspected')
                                        <span class="badge-soft badge-soft-success">Inspected</span>
                                        @if ($i->inspection)<a href="{{ route('inspections.show', $i->inspection) }}" class="text-xs">{{ $i->inspection->inspection_no }}</a>@endif
                                    @elseif ($i->status === 'skipped')
                                        <span class="badge-soft badge-soft-secondary">Skipped</span>
                                    @elseif ($s->status === ScheduleStatus::Published)
                                        @if ($own && $s->schedule_date->isToday())
                                            <form method="POST" action="{{ route('inspections.start', $i->school) }}" data-geo-start>@csrf
                                                <input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="accuracy">
                                                <button class="btn btn-sm btn-primary"><i class="bi bi-play-fill"></i>Start inspection</button></form>
                                        @endif
                                        @if ($own || $superior)
                                            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="collapse" data-bs-target="#skip{{ $i->id }}">Skip</button>
                                        @endif
                                    @endif
                                </div>
                                @if (($own || $superior) && $s->status === ScheduleStatus::Published && $i->status === 'planned')
                                    <form method="POST" action="{{ route('schedules.skip', $i) }}" class="collapse mt-2" id="skip{{ $i->id }}">@csrf
                                        <div class="input-group input-group-sm"><input name="reason" class="form-control" placeholder="Reason for skipping" required minlength="3" maxlength="255"><button class="btn btn-outline-secondary">Skip visit</button></div></form>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">No schools on this plan.</li>
                    @endforelse
                </ol>
            </div>

            @if ($superior && $s->status === ScheduleStatus::Submitted)
                <div class="card mb-3">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-check2-square me-1"></i>Review this plan</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('schedules.approve', $s) }}" class="mb-2">@csrf
                            <input name="note" class="form-control mb-2" maxlength="500" placeholder="Note to the officer (optional)">
                            <button class="btn btn-success w-100"><i class="bi bi-check2-circle"></i>Approve &amp; publish</button></form>
                        <form method="POST" action="{{ route('schedules.reject', $s) }}">@csrf
                            <textarea name="note" class="form-control mb-2 @error('note') is-invalid @enderror" rows="2" maxlength="500" placeholder="Reason for sending it back" required minlength="5"></textarea>
                            @error('note')<div class="invalid-feedback d-block mb-2">{{ $message }}</div>@enderror
                            <button class="btn btn-outline-danger w-100"><i class="bi bi-arrow-return-left"></i>Send back</button></form>
                    </div>
                </div>
            @endif

            @if ($superior && $open && $reassignTo->isNotEmpty() && ! $s->items->contains('status', 'inspected'))
                <div class="card mb-3">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-arrow-left-right me-1"></i>Reassign to another officer</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('schedules.reassign', $s) }}" class="d-flex gap-2">@csrf
                            <select name="officer_id" class="form-select" required>
                                @foreach ($reassignTo as $o)<option value="{{ $o->id }}">{{ $o->name }} · {{ $o->level->label() }}</option>@endforeach
                            </select>
                            <button class="btn btn-soft-primary">Reassign</button></form>
                        @error('officer_id')<div class="text-danger text-sm mt-1">{{ $message }}</div>@enderror
                    </div>
                </div>
            @endif

            @if ($open && ($own || $superior) && ! $s->items->contains('status', 'inspected'))
                <div class="card mb-3">
                    <div class="card-body">
                        <button class="btn btn-link text-danger p-0" data-bs-toggle="collapse" data-bs-target="#cancelPlan"><i class="bi bi-x-circle"></i> Cancel this plan</button>
                        <form method="POST" action="{{ route('schedules.cancel', $s) }}" class="collapse mt-2" id="cancelPlan">@csrf
                            <textarea name="reason" class="form-control mb-2" rows="2" required minlength="5" maxlength="500" placeholder="Reason"></textarea>
                            <button class="btn btn-outline-danger btn-sm">Cancel plan</button></form>
                    </div>
                </div>
            @endif
            @error('school_ids')<div class="alert alert-danger">{!! collect($errors->get('school_ids'))->flatten()->map(fn ($m) => e($m))->implode('<br>') !!}</div>@enderror
            @error('reason')<div class="alert alert-danger">{{ $message }}</div>@enderror

            <div class="card">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-1"></i>History</div>
                <ul class="list-group list-group-flush sched-history">
                    @foreach ($history as $h)
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between gap-2"><span class="fw-semibold text-sm">{{ $labels[$h->action] ?? $h->action }}</span><span class="text-xs text-muted text-nowrap">{{ $h->created_at->format('d M Y, h:i A') }}</span></div>
                            <div class="text-xs text-muted">by {{ $h->user?->name ?? 'system' }}@if (! empty($h->meta['note'])) · “{{ $h->meta['note'] }}”@endif @if (! empty($h->meta['reason'])) · “{{ $h->meta['reason'] }}”@endif</div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    @push('scripts')
        @vite(['resources/js/schedule-route.js'])
    @endpush
</x-layouts.app>
