<x-layouts.app title="Plan Inspections" :back="route('schedules.index', ['date' => $date->toDateString()])">
    {{-- Workflow: Select date → Open map → Select block → Drag and drop schools → Arrange visit order → Save / Submit / Publish --}}
    <form method="GET" class="filter-bar mb-3" id="planPick">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label text-xs mb-1" for="pDate">Inspection date</label>
                <input type="date" id="pDate" name="date" class="form-control" value="{{ $date->toDateString() }}" min="{{ today()->toDateString() }}" data-autosubmit></div>
            @if ($officers->count() > 1)
                <div class="col-6 col-md-4"><label class="form-label text-xs mb-1" for="pOfficer">Inspection officer</label>
                    <select id="pOfficer" name="officer" class="form-select" data-autosubmit>
                        @foreach ($officers as $o)<option value="{{ $o->id }}" @selected($o->id === $officer->id)>{{ $o->name }} · {{ $o->level->label() }}{{ $o->id === auth()->id() ? ' (me)' : '' }}</option>@endforeach
                    </select></div>
            @endif
            @if ($blocks->isNotEmpty())
                <div class="col-12 col-md-3"><label class="form-label text-xs mb-1" for="pBlock">Block</label>
                    <select id="pBlock" class="form-select"><option value="">Choose a block…</option>
                        @foreach ($blocks as $b)<option value="{{ $b->id }}">{{ $b->label }}</option>@endforeach
                    </select></div>
            @endif
            <div class="col-12 col-md text-md-end">
                @if ($schedule)
                    <a href="{{ route('schedules.show', $schedule) }}" class="text-sm">Current plan: <x-status-badge :status="$schedule->status" /></a>
                @endif
            </div>
        </div>
    </form>

    @if ($schedule?->status === \App\Enums\ScheduleStatus::Rejected && $schedule->review_note)
        <div class="alert alert-danger py-2"><i class="bi bi-arrow-return-left me-1"></i><b>Sent back:</b> {{ $schedule->review_note }}</div>
    @endif
    @if ($readonly)
        <div class="alert alert-info py-2"><i class="bi bi-lock me-1"></i>This plan is {{ strtolower($schedule->status->label()) }}. It can be changed only by a higher officer.</div>
    @endif

    <div class="row g-3" id="planner" data-config='@json($config)' data-readonly="{{ $readonly ? 1 : 0 }}">
        <div class="col-lg-7">
            <div class="card map-card">
                <div class="card-body p-2">
                    <div id="planMap" class="si-map plan-map" role="region" aria-label="Schools on the map"></div>
                    <div class="map-loading d-none" id="mapLoading"><span class="spinner-border spinner-border-sm"></span> Loading…</div>
                    <div class="d-flex flex-wrap gap-3 text-xs mt-2 px-1">
                        <span><span class="si-pin-mini st-available"></span>Can be planned</span>
                        <span><span class="si-pin-mini st-inspected"></span>Inspected this month</span>
                        <span><span class="si-pin-mini st-pending"></span>Not available</span>
                        <span><span class="si-pin-mini st-planned"></span>On this plan</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header bg-white d-flex align-items-center gap-2">
                    <span class="fw-semibold flex-grow-1"><i class="bi bi-signpost-split me-1"></i>Day plan · {{ $date->format('D, d M Y') }}</span>
                    <span class="badge text-bg-light" id="planCount">0 / {{ $config['max'] }}</span>
                </div>
                <ol class="list-group list-group-flush plan-list" id="planList" aria-label="Schools in visit order"></ol>
                <div class="plan-empty text-muted text-sm p-3" id="planEmpty"><i class="bi bi-hand-index me-1"></i>Drag schools here from the list below, or tap a school on the map and choose “Add to plan”.</div>
                <div class="card-body border-top py-2">
                    <div class="d-flex flex-wrap gap-3 text-sm mb-2" id="planTotals"></div>
                    <div class="d-flex flex-wrap gap-2 {{ $readonly ? 'd-none' : '' }}">
                        <button type="button" class="btn btn-light btn-sm" id="btnOptimise"><i class="bi bi-magic"></i>Optimise route</button>
                        <button type="button" class="btn btn-light btn-sm" id="btnMyLocation"><i class="bi bi-crosshair"></i>Start from my location</button>
                    </div>
                    <textarea class="form-control form-control-sm mt-2" id="planNotes" rows="2" maxlength="500" placeholder="Notes (optional)" @disabled($readonly)>{{ $schedule?->notes }}</textarea>
                    <div class="text-danger text-sm mt-2 d-none" id="planErrors"></div>
                    <div class="d-flex flex-wrap gap-2 mt-2 {{ $readonly ? 'd-none' : '' }}">
                        @if ($superior)
                            <button type="button" class="btn btn-primary flex-grow-1" data-action="publish"><i class="bi bi-send-check"></i>Assign &amp; publish</button>
                            <button type="button" class="btn btn-light" data-action="save"><i class="bi bi-save"></i>Save</button>
                        @else
                            <button type="button" class="btn btn-light" data-action="save"><i class="bi bi-save"></i>Save draft</button>
                            <button type="button" class="btn btn-primary flex-grow-1" data-action="submit"><i class="bi bi-send"></i>{{ config('inspection.schedule.approval_required') ? 'Submit for approval' : 'Publish plan' }}</button>
                        @endif
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-white">
                    <div class="fw-semibold mb-2"><i class="bi bi-building me-1"></i>Available schools <span class="text-muted text-xs">(nearest first)</span></div>
                    <input type="search" class="form-control form-control-sm" id="availSearch" placeholder="Filter by name or UDISE" aria-label="Filter schools">
                </div>
                <div class="list-group list-group-flush avail-list" id="availList" aria-label="Schools that can be planned"></div>
            </div>
        </div>
    </div>

    @push('scripts')
        @vite(['resources/js/schedule-planner.js'])
    @endpush
</x-layouts.app>
