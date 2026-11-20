<x-layouts.app title="Excel Upload">
    @php
        $type = old('type', 'school');
        $districtId = old('district_id', $pending['district_id'] ?? request('district'));
        $tab = $preview ? 'upload' : request('tab', 'upload');
        $tot = fn ($k) => $report->sum($k);
    @endphp

    <div data-imports data-template-url="{{ route('admin.imports.template', 'school') }}" data-blocks-url="{{ route('admin.imports.district') }}">
    <ul class="nav imp-tabs mb-3" role="tablist">
        <li><button class="{{ $tab === 'upload' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-upload" type="button" role="tab"><i class="bi bi-cloud-upload"></i>Upload</button></li>
        <li><button class="{{ $tab === 'report' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-report" type="button" role="tab"><i class="bi bi-bar-chart"></i>District report <span class="imp-count">{{ number_format($tot('schools')) }}</span></button></li>
        <li><button class="{{ $tab === 'history' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-history" type="button" role="tab"><i class="bi bi-clock-history"></i>History</button></li>
    </ul>

    <div class="tab-content">
    {{-- ================= Upload ================= --}}
    <div class="tab-pane fade {{ $tab === 'upload' ? 'show active' : '' }}" id="tab-upload" role="tabpanel">
        @if ($preview && $pending)
            {{-- Preview of the checked file (nothing saved yet) --}}
            <div class="imp-card imp-preview mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h2 class="imp-title"><i class="bi bi-clipboard-check"></i>File checked: nothing is saved yet</h2>
                        <div class="text-sm text-secondary"><i class="bi bi-file-earmark-excel me-1"></i>{{ $pending['name'] }} · <b>{{ $pending['district'] }}</b> district</div>
                    </div>
                </div>
                <div class="imp-tiles mb-3">
                    <div class="t-total"><b>{{ $preview['total'] }}</b><span>Rows found</span></div>
                    <div class="t-new"><b>{{ $preview['added'] }}</b><span>New schools</span></div>
                    <div class="t-upd"><b>{{ $preview['updated'] }}</b><span>Will be updated</span></div>
                    <div class="t-err"><b>{{ $preview['rejected'] }}</b><span>Errors</span></div>
                </div>
                @if ($preview['summary']['blocks'] ?? [])
                    <div class="table-responsive mb-3">
                        <table class="table table-sm imp-table mb-0">
                            <thead><tr><th>Block</th><th class="text-end">New</th><th class="text-end">Updated</th><th class="text-end">Errors</th></tr></thead>
                            <tbody>
                                @foreach (collect($preview['summary']['blocks'])->sortBy('name') as $b)
                                    <tr><td>{{ $b['name'] }}</td><td class="text-end text-success fw-semibold">{{ $b['added'] }}</td><td class="text-end text-primary fw-semibold">{{ $b['updated'] }}</td><td class="text-end {{ $b['rejected'] ? 'text-danger fw-semibold' : 'text-secondary' }}">{{ $b['rejected'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @if ($preview['errors'])
                    <div class="imp-errors mb-3">
                        <div class="fw-semibold text-sm mb-1"><i class="bi bi-exclamation-triangle text-danger me-1"></i>Rows that will be skipped{{ $preview['rejected'] > count($preview['errors']) ? ' (first '.count($preview['errors']).' of '.$preview['rejected'].')' : '' }}</div>
                        <ul>
                            @foreach ($preview['errors'] as $e)
                                <li><span class="imp-row">Row {{ $e['row'] }}</span>@if ($e['udise'])<span class="rs-code me-1">{{ $e['udise'] }}</span>@endif{{ $e['reason'] }}</li>
                            @endforeach
                        </ul>
                        <div class="text-xs text-secondary">After saving, the full list is available as an Excel file under History.</div>
                    </div>
                @endif
                <div class="d-flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.imports.confirm') }}">@csrf
                        <button class="btn btn-success" @disabled($preview['added'] + $preview['updated'] === 0)><i class="bi bi-check2-circle"></i>Confirm and save {{ $preview['added'] + $preview['updated'] }} {{ \Illuminate\Support\Str::plural('school', $preview['added'] + $preview['updated']) }}</button>
                    </form>
                    <form method="POST" action="{{ route('admin.imports.cancel') }}">@csrf
                        <button class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i>Cancel</button>
                    </form>
                </div>
            </div>
        @endif

        <div class="row g-4">
            <div class="col-lg-8">
                <form method="POST" action="{{ $type === 'school' ? route('admin.imports.preview') : route('admin.imports.store') }}" enctype="multipart/form-data" class="imp-card" id="imp-form"
                      data-preview-url="{{ route('admin.imports.preview') }}" data-store-url="{{ route('admin.imports.store') }}">
                    @csrf
                    {{-- Step 1 --}}
                    <div class="imp-step">
                        <span class="imp-step-no">1</span>
                        <div class="imp-step-body">
                            <h3>Choose what to upload</h3>
                            <div class="choice-group mb-3">
                                <input type="radio" name="type" id="t_school" value="school" @checked($type === 'school')><label for="t_school"><i class="bi bi-building"></i>School Master</label>
                                <input type="radio" name="type" id="t_location" value="location" @checked($type === 'location')><label for="t_location"><i class="bi bi-geo-alt"></i>Location Master</label>
                            </div>
                            <div data-school-only @class(['d-none' => $type !== 'school'])>
                                <label class="form-label" for="district_id">District</label>
                                <select name="district_id" id="district_id" class="form-select @error('district_id') is-invalid @enderror" @required($type === 'school')>
                                    <option value="">Choose a district…</option>
                                    @foreach ($districts as $d)
                                        @php $r = $report->firstWhere('id', $d->id); @endphp
                                        <option value="{{ $d->id }}" @selected((string) $districtId === (string) $d->id)>{{ $d->name }} ({{ $d->code }}) · {{ $r->schools ?? 0 }} schools</option>
                                    @endforeach
                                </select>
                                @error('district_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">No District or Block columns are needed: they are taken from each school's cluster code.</div>
                            </div>
                        </div>
                    </div>
                    {{-- Step 2 --}}
                    <div class="imp-step">
                        <span class="imp-step-no">2</span>
                        <div class="imp-step-body">
                            <h3>Download the template</h3>
                            <div data-school-only @class(['d-none' => $type !== 'school'])>
                                <a href="{{ $districtId ? route('admin.imports.template', ['school', 'district' => $districtId]) : '#' }}" class="btn btn-soft-primary btn-sm @if (! $districtId) disabled @endif" data-district-template><i class="bi bi-download"></i>School template for the district</a>
                                <div class="form-text">Columns: <code>udise_code</code>, <code>school_name</code>, <code>cluster_code</code> (required), then management, category, address, latitude, longitude, status. The second sheet lists the district's cluster codes.</div>
                            </div>
                            <div data-location-only @class(['d-none' => $type === 'school'])>
                                <a href="{{ route('admin.imports.template', 'location') }}" class="btn btn-soft-primary btn-sm"><i class="bi bi-download"></i>Location template</a>
                                <div class="form-text">Upload the Location Master before the School Master.</div>
                            </div>
                        </div>
                    </div>
                    {{-- Step 3 --}}
                    <div class="imp-step">
                        <span class="imp-step-no">3</span>
                        <div class="imp-step-body">
                            <h3>Upload the filled file</h3>
                            <label class="imp-drop @error('file') is-invalid @enderror" for="file" data-drop>
                                <input type="file" name="file" id="file" accept=".xlsx,.xls,.csv" required>
                                <i class="bi bi-cloud-arrow-up imp-drop-icon"></i>
                                <span class="imp-drop-text" data-drop-text><b>Drag your Excel file here</b> or <u>browse</u></span>
                                <span class="imp-drop-hint">.xlsx, .xls or .csv · up to 20 MB</span>
                            </label>
                            @error('file')<div class="text-danger text-sm mt-1">{{ $message }}</div>@enderror
                            <button class="btn btn-primary imp-submit mt-3" data-submit>
                                <span data-school-only @class(['d-none' => $type !== 'school'])><i class="bi bi-search"></i>Check file</span>
                                <span data-location-only @class(['d-none' => $type === 'school'])><i class="bi bi-cloud-upload"></i>Upload &amp; save</span>
                            </button>
                            <div class="form-text" data-school-only @class(['d-none' => $type !== 'school'])>You will see what will be added or updated before anything is saved.</div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="col-lg-4">
                <div class="imp-card imp-checks">
                    <h3 class="imp-title"><i class="bi bi-shield-check"></i>Checks before saving</h3>
                    <ul>
                        <li>UDISE Code is exactly 11 digits</li>
                        <li>No duplicate UDISE codes in the file</li>
                        <li>The cluster code exists and is in the chosen district</li>
                        <li>School name and cluster code are filled</li>
                        <li>Existing UDISE codes are updated, new ones are added; schools missing from the file are <b>not</b> removed</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= District report ================= --}}
    <div class="tab-pane fade {{ $tab === 'report' ? 'show active' : '' }}" id="tab-report" role="tabpanel">
        <div class="imp-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="imp-title mb-0"><i class="bi bi-bar-chart"></i>Schools by district</h2>
                <a href="{{ route('admin.imports.report') }}" class="btn btn-sm btn-soft-success"><i class="bi bi-file-earmark-excel"></i>Export Excel</a>
            </div>
            <div class="imp-tiles mb-3">
                <div class="t-total"><b>{{ number_format($tot('schools')) }}</b><span>Schools</span></div>
                <div class="t-new"><b>{{ number_format($tot('active')) }}</b><span>Active</span></div>
                <div class="t-upd"><b>{{ number_format($tot('gps')) }}</b><span>With GPS</span></div>
                <div class="t-err"><b>{{ number_format($tot('closed')) }}</b><span>Closed</span></div>
            </div>
            <div class="table-responsive">
                <table class="table imp-table imp-report mb-0">
                    <thead><tr><th>District</th><th class="text-end">Blocks</th><th class="text-end">Clusters</th><th class="text-end">Schools</th><th class="text-end">Active</th><th class="text-end">Closed</th><th class="text-end">With GPS</th><th>Last upload</th></tr></thead>
                    <tbody>
                        @foreach ($report as $d)
                            <tr class="imp-district" data-district="{{ $d->id }}" tabindex="0" role="button" aria-expanded="false">
                                <td><i class="bi bi-chevron-right imp-caret"></i><b>{{ $d->name }}</b> <span class="text-xs text-secondary">{{ $d->code }}</span></td>
                                <td class="text-end">{{ $d->blocks }}</td><td class="text-end">{{ $d->clusters }}</td>
                                <td class="text-end fw-bold">{{ number_format($d->schools) }}</td>
                                <td class="text-end text-success">{{ number_format($d->active) }}</td><td class="text-end text-secondary">{{ number_format($d->closed) }}</td>
                                <td class="text-end">{{ number_format($d->gps) }} @if ($d->schools)<span class="text-xs text-secondary">({{ round($d->gps / $d->schools * 100) }}%)</span>@endif</td>
                                <td class="text-xs text-secondary">{{ $d->last_upload?->format('d M Y') ?? 'Not yet' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><th>Total</th><th class="text-end">{{ $tot('blocks') }}</th><th class="text-end">{{ $tot('clusters') }}</th><th class="text-end">{{ number_format($tot('schools')) }}</th><th class="text-end">{{ number_format($tot('active')) }}</th><th class="text-end">{{ number_format($tot('closed')) }}</th><th class="text-end">{{ number_format($tot('gps')) }}</th><th></th></tr></tfoot>
                </table>
            </div>
            <div class="text-xs text-secondary mt-2"><i class="bi bi-hand-index me-1"></i>Tap a district to see its blocks.</div>
        </div>
    </div>

    {{-- ================= History ================= --}}
    <div class="tab-pane fade {{ $tab === 'history' ? 'show active' : '' }}" id="tab-history" role="tabpanel">
        <div class="imp-card p-0">
            <div class="list-card">
                @forelse ($batches as $b)
                    <div class="list-item flex-wrap {{ session('import_result') == $b->id ? 'bg-primary-subtle' : '' }}">
                        <span class="stat-icon tone-{{ $b->type === 'school' ? 'primary' : 'accent' }}" style="width:40px;height:40px;border-radius:10px;display:grid;place-items:center"><i class="bi bi-{{ $b->type === 'school' ? 'building' : 'geo-alt' }}"></i></span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold text-truncate">{{ $b->file_name }}</div>
                            <div class="text-xs text-secondary">{{ ucfirst($b->type) }} master @if ($b->district)· <span class="imp-chip"><i class="bi bi-geo"></i>{{ $b->district->name }}</span>@endif · {{ $b->created_at->format('d M Y, h:i A') }} · {{ $b->uploader?->name }}</div>
                            <div class="d-flex gap-2 mt-1 text-xs flex-wrap">
                                <span class="badge-soft badge-soft-success">{{ $b->added }} added</span>
                                <span class="badge-soft badge-soft-info">{{ $b->updated }} updated</span>
                                <span class="badge-soft badge-soft-{{ $b->rejected ? 'danger' : 'secondary' }}">{{ $b->rejected }} rejected</span>
                                @if ($b->summary['blocks'] ?? [])
                                    <a class="text-xs" data-bs-toggle="collapse" href="#sum-{{ $b->id }}" role="button" aria-expanded="false">By block <i class="bi bi-chevron-down"></i></a>
                                @endif
                            </div>
                            @if ($b->summary['blocks'] ?? [])
                                <div class="collapse" id="sum-{{ $b->id }}">
                                    <table class="table table-sm imp-table mt-2 mb-0">
                                        <thead><tr><th>Block</th><th class="text-end">Added</th><th class="text-end">Updated</th><th class="text-end">Rejected</th></tr></thead>
                                        <tbody>
                                            @foreach (collect($b->summary['blocks'])->sortBy('name') as $s)
                                                <tr><td>{{ $s['name'] }}</td><td class="text-end">{{ $s['added'] }}</td><td class="text-end">{{ $s['updated'] }}</td><td class="text-end">{{ $s['rejected'] }}</td></tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                        @if ($b->error_file_path)
                            <a href="{{ route('admin.imports.errors', $b) }}" class="btn btn-sm btn-soft-danger"><i class="bi bi-file-earmark-excel"></i>Error list</a>
                        @endif
                    </div>
                @empty
                    <x-empty-state icon="bi-file-earmark-spreadsheet" title="No uploads yet" />
                @endforelse
            </div>
        </div>
        <div class="mt-3">{{ $batches->appends(['tab' => 'history'])->links() }}</div>
    </div>
    </div>
    </div>
</x-layouts.app>
