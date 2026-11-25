{{-- 25-11-2026: the day popup of the Holiday Calendar and the dashboard calendar (filled by resources/js/holiday-day.js
     from the clicked cell's data-holiday-day). Lists the day's holidays; managers can remove one and add a holiday on that date.
     Needs $canManage, $editableDistricts and $back ('dashboard' or ''). --}}
@php
    $dayOld = (bool) old('_day');
    $dayErr = $dayOld ? $errors : new \Illuminate\Support\MessageBag;
@endphp
<div class="modal fade" id="holiday-day" tabindex="-1" aria-labelledby="holiday-day-title" aria-hidden="true" @if ($dayOld && $errors->any()) data-reopen @endif
     data-old-date="{{ $dayOld ? old('date') : '' }}">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="holiday-day-title"><i class="bi bi-calendar-event text-primary me-2"></i><span data-day-title>{{ $dayOld && old('date') ? \Carbon\Carbon::parse(old('date'))->format('l, j F Y') : 'Holiday' }}</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2" data-day-off hidden><span class="badge text-bg-light border"><i class="bi bi-moon me-1"></i>Weekly off</span></div>
                <div data-day-list class="holiday-day-list"></div>
                <div data-day-empty class="text-sm text-secondary py-2" hidden><i class="bi bi-calendar-x me-1"></i>No holiday on this day.</div>

                @if ($canManage)
                    <form method="POST" action="{{ route('holidays.store') }}" class="border-top pt-3 mt-3" data-day-add>
                        @csrf
                        <input type="hidden" name="_day" value="1">
                        <input type="hidden" name="date" value="{{ $dayOld ? old('date') : '' }}" data-day-date>
                        @if ($back)<input type="hidden" name="back" value="{{ $back }}">@endif
                        <div class="fw-semibold text-sm mb-2"><i class="bi bi-plus-circle text-primary me-1"></i>Add a holiday on this date</div>
                        @if ($dayErr->any())<div class="alert alert-danger py-2 text-sm">{{ $dayErr->first() }}</div>@endif
                        <div class="row g-2">
                            <div class="col-12"><label class="form-label" for="hd-name">Holiday name</label>
                                <input id="hd-name" name="name" value="{{ $dayOld ? old('name') : '' }}" maxlength="150" class="form-control {{ $dayErr->has('name') ? 'is-invalid' : '' }}" placeholder="e.g. Bohag Bihu" required></div>
                            <div class="col-sm-5"><label class="form-label" for="hd-type">Type</label>
                                <select id="hd-type" name="type" class="form-select">
                                    @foreach (\App\Models\Holiday::TYPES as $k => $l)<option value="{{ $k }}" @selected(($dayOld ? old('type') : 'gazetted') === $k)>{{ $l }}</option>@endforeach
                                </select></div>
                            <div class="col-sm-7"><label class="form-label" for="hd-scope">Applies to</label>
                                <select id="hd-scope" name="district_id" class="form-select {{ $dayErr->has('district_id') ? 'is-invalid' : '' }}">
                                    @can('state_admin')<option value="">Whole state (state holiday)</option>@endcan
                                    @foreach ($editableDistricts as $d)<option value="{{ $d->id }}" @selected($dayOld && (int) old('district_id') === $d->id)>Local · {{ $d->label }}</option>@endforeach
                                </select></div>
                        </div>
                        <button class="btn btn-primary w-100 mt-3"><i class="bi bi-plus-lg"></i>Add holiday</button>
                    </form>
                    {{-- one Remove form, pointed at the chosen holiday by holiday-day.js --}}
                    <form method="POST" action="" data-day-remove hidden data-confirm="Remove this holiday from the calendar?">
                        @csrf @method('DELETE')
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
