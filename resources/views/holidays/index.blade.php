@php
    $from = $month->copy()->startOfMonth()->startOfWeek(\Carbon\Carbon::MONDAY);
    $to = $month->copy()->endOfMonth()->endOfWeek(\Carbon\Carbon::SUNDAY);
    $keep = request()->only('district', 'restricted');
    $q = fn ($over) => route('holidays.index', array_merge($keep, $over));
    $isOld = fn () => ! old('_edit') && ! old('_day'); // the day popup has its own old input
    $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
@endphp
<x-layouts.app title="Holiday Calendar">
    @if ($canManage)
        <x-slot:actions>
            <div class="dropdown">
                <button class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-download"></i><span class="d-none d-sm-inline">Template</span></button>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                    <li><a class="dropdown-item" href="{{ route('holidays.template') }}" data-no-loader><i class="bi bi-file-earmark-excel text-success"></i>Blank template</a></li>
                    <li><a class="dropdown-item" href="{{ route('holidays.template', ['fill' => 'assam-2026']) }}" data-no-loader><i class="bi bi-file-earmark-spreadsheet text-success"></i>Assam holiday list 2026 (filled)</a></li>
                </ul>
            </div>
            <button type="button" class="btn btn-soft-primary" data-bs-toggle="modal" data-bs-target="#holiday-import"><i class="bi bi-upload"></i><span class="d-none d-sm-inline">Import</span></button>
        </x-slot:actions>
    @endif

    @if (session('import_errors'))
        <div class="alert alert-danger text-sm"><b>Import problems</b><ul class="mb-0 mt-1">@foreach (session('import_errors') as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <div class="filter-bar mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-auto d-flex gap-1 align-items-center">
                <a href="{{ $q(['month' => $month->copy()->subMonthNoOverflow()->format('Y-m')]) }}" class="btn btn-light btn-icon" aria-label="Previous month"><i class="bi bi-chevron-left"></i></a>
                <input type="month" name="month" value="{{ $month->format('Y-m') }}" class="form-control" data-autosubmit aria-label="Month">
                <a href="{{ $q(['month' => $month->copy()->addMonthNoOverflow()->format('Y-m')]) }}" class="btn btn-light btn-icon" aria-label="Next month"><i class="bi bi-chevron-right"></i></a>
                <a href="{{ $q(['month' => today()->format('Y-m')]) }}" class="btn btn-light">Today</a>
            </div>
            @if ($districts->isNotEmpty())
                <div class="col-12 col-md-3"><label class="form-label text-xs mb-1" for="h-district">Local holidays of</label>
                    <select id="h-district" name="district" class="form-select" data-autosubmit>
                        <option value="">State holidays only</option>
                        @foreach ($districts as $d)<option value="{{ $d->id }}" @selected((int) $district === $d->id)>{{ $d->label }}</option>@endforeach
                    </select></div>
            @endif
            <div class="col-12 col-md-auto"><label class="form-label text-xs mb-1" for="h-restricted">Restricted holidays</label>
                <select id="h-restricted" name="restricted" class="form-select" data-autosubmit>
                    <option value="1" @selected($showRestricted)>Show</option>
                    <option value="0" @selected(! $showRestricted)>Hide</option>
                </select></div>
            <div class="col-12 col-md-auto"><x-filter-buttons :reset="route('holidays.index')" /></div>
        </form>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <h2 class="h6 mb-2">{{ $month->format('F Y') }}</h2>
            <div class="sched-grid month">
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)<div class="sched-head d-none d-md-block">{{ $d }}</div>@endforeach
                @for ($d = $from->copy(); $d->lte($to); $d->addDay())
                    @php
                        $key = $d->toDateString();
                        $out = ! $d->isSameMonth($month);
                        $list = $byDate->get($key, collect());
                        $isOffDay = $list->contains(fn ($h) => $h->isOff());
                        $off = in_array((int) $d->dayOfWeek, $weeklyOff, true);
                    @endphp
                    @if ($out)
                        <div class="sched-day is-out d-none d-md-block"></div>
                    @else
                        {{-- 25-11-2026: click a date: its holidays in a popup; managers add / remove there --}}
                        @php $clickable = $canManage || $list->isNotEmpty(); @endphp
                        <div class="sched-day {{ $d->isToday() ? 'is-today' : '' }} {{ $isOffDay ? 'is-holiday' : '' }} {{ $off ? 'is-off' : '' }} {{ $list->isEmpty() && ! $off ? 'd-none d-md-block' : '' }} {{ $clickable ? 'is-clickable' : '' }}"
                            @if ($clickable) data-holiday-day="{{ json_encode($calendar->dayData(auth()->user(), $d, $list, $off)) }}" role="button" tabindex="0"
                                aria-label="{{ $d->format('j M') }}: {{ $list->isEmpty() ? ($canManage ? 'add a holiday' : 'no holiday') : $list->count().' holiday'.($list->count() > 1 ? 's' : '') }}" @endif>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="sched-date"><span class="d-md-none">{{ $d->format('D') }} </span>{{ $d->format('j') }}</span>
                                @if ($off)<span class="text-xs text-secondary">Off</span>@elseif ($canManage)<i class="bi bi-plus-circle sched-add-hint" aria-hidden="true"></i>@endif
                            </div>
                            @foreach ($list as $h)
                                <span class="holiday-chip {{ $h->chipClass() }}" title="{{ $h->typeLabel() }} · {{ $h->areaLabel() }}">{{ $h->name }}</span>
                            @endforeach
                        </div>
                    @endif
                @endfor
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3 text-xs">
                <span class="holiday-chip is-state">State holiday</span>
                <span class="holiday-chip is-local">Local (district) holiday</span>
                <span class="holiday-chip is-restricted">Restricted holiday (office open)</span>
                <span class="holiday-chip is-half">Half holiday (from 1 PM)</span>
                <span class="text-secondary">Weekly off: {{ collect($weeklyOff)->map(fn ($n) => $dayNames[$n] ?? $n)->implode(', ') }}</span>
            </div>
        </div>

        <div class="col-lg-4">
            @if ($canManage)
                <div class="card card-elevated mb-3">
                    <div class="card-header"><i class="bi bi-plus-circle me-2 text-primary"></i>Add holiday</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('holidays.store') }}">
                            @csrf
                            <label class="form-label" for="h-date">Date</label>
                            <input type="date" id="h-date" name="date" value="{{ $isOld() ? old('date') : '' }}" class="form-control mb-3 @error('date') is-invalid @enderror" required>
                            @error('date')<div class="invalid-feedback mt-n2 mb-2">{{ $message }}</div>@enderror
                            <label class="form-label" for="h-name">Holiday name</label>
                            <input id="h-name" name="name" value="{{ $isOld() ? old('name') : '' }}" maxlength="150" class="form-control mb-3 @error('name') is-invalid @enderror" placeholder="e.g. Bohag Bihu" required>
                            @error('name')<div class="invalid-feedback mt-n2 mb-2">{{ $message }}</div>@enderror
                            <label class="form-label" for="h-type">Type</label>
                            <select id="h-type" name="type" class="form-select mb-3 @error('type') is-invalid @enderror">
                                @foreach (\App\Models\Holiday::TYPES as $k => $l)<option value="{{ $k }}" @selected(($isOld() ? old('type') : null) === $k)>{{ $l }}</option>@endforeach
                            </select>
                            <label class="form-label" for="h-scope">Applies to</label>
                            <select id="h-scope" name="district_id" class="form-select mb-3 @error('district_id') is-invalid @enderror">
                                @can('state_admin')<option value="">Whole state (state holiday)</option>@endcan
                                @foreach ($editableDistricts as $d)<option value="{{ $d->id }}" @selected((int) old('district_id', $district) === $d->id)>Local · {{ $d->label }}</option>@endforeach
                            </select>
                            @error('district_id')<div class="invalid-feedback mt-n2 mb-2">{{ $message }}</div>@enderror
                            <button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i>Add</button>
                        </form>
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-calendar-heart me-2 text-primary"></i>Holidays in {{ $year }}</span><span class="badge text-bg-light">{{ $holidays->count() }}</span></div>
                @if ($holidays->isEmpty())
                    <x-empty-state icon="bi-calendar-x" title="No holidays yet" :text="$canManage ? 'Add one with the form above, or import the Excel template.' : 'The administrator has not added holidays for this year.'" />
                @else
                    <ul class="list-group list-group-flush holiday-list">
                        @foreach ($holidays as $h)
                            @php $editable = $calendar->canManage(auth()->user(), $h->district_id); @endphp
                            <li class="list-group-item d-flex gap-2 align-items-center {{ $h->date < today()->toDateString() ? 'text-secondary' : '' }}">
                                <span class="holiday-date"><b>{{ \Carbon\Carbon::parse($h->date)->format('d') }}</b>{{ \Carbon\Carbon::parse($h->date)->format('M') }}</span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold text-truncate">{{ $h->name }}</div>
                                    <div class="text-xs"><span class="holiday-chip {{ $h->chipClass() }} d-inline-block">{{ $h->isOff() ? $h->areaLabel() : $h->typeLabel().($h->isState() ? '' : ' · '.$h->district?->label) }}</span> · {{ \Carbon\Carbon::parse($h->date)->format('l') }}</div>
                                </div>
                                @if ($editable)
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light btn-icon" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-label="Actions for {{ $h->name }}"><i class="bi bi-three-dots-vertical"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 row-actions">
                                            <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#holiday-edit-{{ $h->id }}"><i class="bi bi-pencil-square text-primary"></i>Edit</button></li>
                                            <li><form method="POST" action="{{ route('holidays.destroy', $h) }}" data-confirm="Delete {{ $h->name }} ({{ \Carbon\Carbon::parse($h->date)->format('d M Y') }})?">
                                                @csrf @method('DELETE')<button class="dropdown-item text-danger"><i class="bi bi-trash"></i>Delete</button></form></li>
                                        </ul>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    {{-- Edit windows, outside the list (V12 item 7 pattern) --}}
    @foreach ($holidays as $h)
        @continue(! $calendar->canManage(auth()->user(), $h->district_id))
        @php
            $mine = (string) old('_edit') === (string) $h->id;
            $v = fn ($f, $d) => $mine ? old($f, $d) : $d;
            $err = $mine ? $errors->edit : new \Illuminate\Support\MessageBag;
        @endphp
        <div class="modal fade" id="holiday-edit-{{ $h->id }}" tabindex="-1" aria-labelledby="holiday-edit-title-{{ $h->id }}" aria-hidden="true" @if ($mine && $errors->edit->any()) data-reopen @endif>
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" action="{{ route('holidays.update', $h) }}" class="modal-content">
                    @csrf @method('PUT')
                    <input type="hidden" name="_edit" value="{{ $h->id }}">
                    <div class="modal-header">
                        <h5 class="modal-title" id="holiday-edit-title-{{ $h->id }}"><i class="bi bi-pencil-square text-primary me-2"></i>Edit holiday</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @if ($err->any())<div class="alert alert-danger py-2 text-sm">{{ $err->first() }}</div>@endif
                        <label class="form-label">Date</label>
                        <input type="date" name="date" value="{{ $v('date', $h->date) }}" class="form-control mb-3" required>
                        <label class="form-label">Holiday name</label>
                        <input name="name" value="{{ $v('name', $h->name) }}" maxlength="150" class="form-control mb-3" required>
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select mb-3">
                            @foreach (\App\Models\Holiday::TYPES as $k => $l)<option value="{{ $k }}" @selected($v('type', $h->type) === $k)>{{ $l }}</option>@endforeach
                        </select>
                        <label class="form-label">Applies to</label>
                        <select name="district_id" class="form-select">
                            @can('state_admin')<option value="" @selected($v('district_id', $h->district_id) === null)>Whole state (state holiday)</option>@endcan
                            @foreach ($editableDistricts as $d)<option value="{{ $d->id }}" @selected((int) $v('district_id', $h->district_id) === $d->id)>Local · {{ $d->label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-primary"><i class="bi bi-check-lg"></i>Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

    @if ($canManage)
        <div class="modal fade" id="holiday-import" tabindex="-1" aria-labelledby="holiday-import-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" action="{{ route('holidays.import') }}" enctype="multipart/form-data" class="modal-content" data-loader="heavy" data-loader-text="Importing holidays…">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="holiday-import-title"><i class="bi bi-upload text-primary me-2"></i>Import holidays</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-sm text-secondary">Columns: <code>date</code> (YYYY-MM-DD), <code>name</code>, <code>type</code> (gazetted, restricted or half; blank = gazetted), <code>district_code</code>{{ auth()->user()->isStateAdmin() ? ' (leave blank for a state holiday)' : ' (your district code)' }}. Every row is checked first: nothing is saved while any row has a problem. Holidays already in the calendar are skipped.</p>
                        <input type="file" name="file" accept=".xlsx,.xls,.csv" class="form-control @error('file') is-invalid @enderror" required>
                        @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <a href="{{ route('holidays.template') }}" class="d-inline-block mt-2 text-sm" data-no-loader><i class="bi bi-download me-1"></i>Download the template</a>
                        <a href="{{ route('holidays.template', ['fill' => 'assam-2026']) }}" class="d-inline-block mt-2 ms-3 text-sm" data-no-loader><i class="bi bi-file-earmark-spreadsheet me-1"></i>Assam list 2026</a>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-primary"><i class="bi bi-upload"></i>Import</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
    @include('holidays.partials.day-modal', ['back' => ''])
</x-layouts.app>
