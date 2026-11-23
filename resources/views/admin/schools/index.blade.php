<x-layouts.app title="School Master">
    <x-slot:actions>
        <span id="sm-actions" class="d-contents" data-filter-region><a href="{{ route('admin.schools.export', request()->only(\App\Http\Controllers\Admin\SchoolMasterController::FILTERS)) }}" class="btn btn-soft-primary d-none d-sm-inline-flex" data-no-loader title="The whole state (44,000 schools) takes 2–3 minutes; filter by district for a quicker file"><i class="bi bi-file-earmark-excel"></i>Export Excel</a>
        <a href="{{ route('admin.schools.export-pdf', request()->only(\App\Http\Controllers\Admin\SchoolMasterController::FILTERS)) }}" class="btn btn-soft-primary d-none d-sm-inline-flex" data-no-loader title="PDF of the filtered list (up to {{ number_format(config('inspection.master_pdf_max_rows')) }} schools)"><i class="bi bi-file-earmark-pdf"></i>Export PDF</a></span>
        <a href="{{ route('admin.schools.create') }}" class="btn btn-primary d-none d-sm-inline-flex"><i class="bi bi-plus-lg"></i>Add school</a>
    </x-slot:actions>

    {{-- Modification V13 item 2: filters by location, AC / PC, management, category and area --}}
    <form method="GET" id="sm-form" class="filter-bar mb-3 row g-2" data-filter data-filter-region data-loader-text="Loading schools…">
        <div class="col-12 col-md-4"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="UDISE code or school name" aria-label="Search" maxlength="100" data-school-suggest="{{ route('schools.suggest', ['all' => 1]) }}"></div>
        <div class="col-6 col-md-2">
            <select name="district_id" class="form-select" data-autosubmit aria-label="District"><option value="">All districts</option>
                @foreach ($districts as $d)<option value="{{ $d->id }}" @selected(request('district_id') == $d->id)>{{ $d->label }}</option>@endforeach</select>
        </div>
        <div class="col-6 col-md-2">
            <select name="block_id" class="form-select" data-autosubmit aria-label="Block" @disabled($blocks->isEmpty())><option value="">All blocks</option>
                @foreach ($blocks as $b)<option value="{{ $b->id }}" @selected(request('block_id') == $b->id)>{{ $b->label }}</option>@endforeach</select>
        </div>
        <div class="col-6 col-md-2">
            <select name="cluster_id" class="form-select" data-autosubmit aria-label="Cluster" @disabled($clusters->isEmpty())><option value="">All clusters</option>
                @foreach ($clusters as $c)<option value="{{ $c->id }}" @selected(request('cluster_id') == $c->id)>{{ $c->label }}</option>@endforeach</select>
        </div>
        <div class="col-6 col-md-2">
            <select name="status" class="form-select" data-autosubmit aria-label="Status"><option value="">Any status</option>
                <option value="active" @selected(request('status') === 'active')>Active</option><option value="closed" @selected(request('status') === 'closed')>Closed</option></select>
        </div>
        <div class="col-6 col-md-2">
            <select name="pc_id" class="form-select" data-autosubmit aria-label="Lok Sabha (PC)"><option value="">All PCs</option>
                @foreach ($pcs as $p)<option value="{{ $p->id }}" @selected(request('pc_id') == $p->id)>{{ $p->name }}</option>@endforeach</select>
        </div>
        <div class="col-6 col-md-2">
            <select name="ac_id" class="form-select" data-autosubmit aria-label="Assembly (AC)"><option value="">All ACs</option>
                @foreach ($acs as $a)<option value="{{ $a->id }}" @selected(request('ac_id') == $a->id)>{{ $a->name }} · {{ $a->code }}</option>@endforeach</select>
        </div>
        <div class="col-6 col-md-2">
            <select name="management_id" class="form-select" data-autosubmit aria-label="Management"><option value="">Any management</option>
                @foreach ($managements as $m)<option value="{{ $m->id }}" @selected(request('management_id') == $m->id)>{{ $m->label }}</option>@endforeach</select>
        </div>
        <div class="col-6 col-md-2">
            <select name="category_id" class="form-select" data-autosubmit aria-label="Category"><option value="">Any category</option>
                @foreach ($categories as $m)<option value="{{ $m->id }}" @selected(request('category_id') == $m->id)>{{ $m->label }}</option>@endforeach</select>
        </div>
        <div class="col-6 col-md-1">
            <select name="area_type" class="form-select" data-autosubmit aria-label="Rural / Urban"><option value="">Area</option>
                @foreach (\App\Models\School::AREA_TYPES as $k => $l)<option value="{{ $k }}" @selected(request('area_type') === $k)>{{ $l }}</option>@endforeach</select>
        </div>
        <div class="col-6 col-md-1">
            <select name="school_type" class="form-select" data-autosubmit aria-label="School type"><option value="">Type</option>
                @foreach (\App\Models\School::SCHOOL_TYPES as $k => $l)<option value="{{ $k }}" @selected(request('school_type') === $k)>{{ $l }}</option>@endforeach</select>
        </div>
        <div class="col-12 col-md-auto ms-md-auto"><x-filter-buttons :reset="route('admin.schools.index')" /></div>
    </form>
    <div id="sm-results" data-filter-region data-filter-results>
    <div class="d-flex flex-wrap gap-2 mb-3 d-sm-none">
        <a href="{{ route('admin.schools.create') }}" class="btn btn-primary flex-grow-1"><i class="bi bi-plus-lg"></i>Add school</a>
        <a href="{{ route('admin.schools.export', request()->only(\App\Http\Controllers\Admin\SchoolMasterController::FILTERS)) }}" class="btn btn-soft-primary flex-grow-1" data-no-loader><i class="bi bi-file-earmark-excel"></i>Excel</a>
        <a href="{{ route('admin.schools.export-pdf', request()->only(\App\Http\Controllers\Admin\SchoolMasterController::FILTERS)) }}" class="btn btn-soft-primary flex-grow-1" data-no-loader><i class="bi bi-file-earmark-pdf"></i>PDF</a>
    </div>

    <div class="text-sm text-secondary mb-2" data-school-count>
        @if ($filtered)
            <strong class="text-body">{{ number_format($schools->total()) }}</strong> of {{ number_format($total) }} schools match
        @else
            <strong class="text-body">{{ number_format($total) }}</strong> schools in the School Master
        @endif
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>UDISE</th><th>School</th><th class="d-none d-md-table-cell">Block / Cluster</th><th class="d-none d-lg-table-cell">Category / Management</th><th class="d-none d-xl-table-cell">Type · Area</th><th class="d-none d-xl-table-cell">AC / PC</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse ($schools as $s)
                    <tr>
                        <td class="font-monospace text-sm">{{ $s->udise_code }}</td>
                        <td><div class="fw-semibold">{{ $s->name }}</div><div class="text-xs text-secondary">{{ $s->district?->label }}</div></td>
                        <td class="d-none d-md-table-cell text-sm">{{ $s->block?->label }}<div class="text-xs text-secondary">{{ $s->cluster?->label }}</div></td>
                        <td class="d-none d-lg-table-cell text-sm">{{ $s->category?->label }}<div class="text-xs text-secondary">{{ $s->management?->label }}</div></td>
                        <td class="d-none d-xl-table-cell text-sm">{{ collect([$s->school_type_label, $s->area_type_label])->filter()->join(' · ') ?: '—' }}</td>
                        <td class="d-none d-xl-table-cell text-sm">{{ $s->ac?->name ?? '—' }}<div class="text-xs text-secondary">{{ $s->pc?->name }}</div></td>
                        <td><span class="badge-soft badge-soft-{{ $s->status === 'active' ? 'success' : 'secondary' }}">{{ ucfirst($s->status) }}</span></td>
                        <td class="text-end">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light btn-icon" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-label="Actions for {{ $s->name }}"><i class="bi bi-three-dots-vertical"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 row-actions">
                                    <li><a class="dropdown-item" href="{{ route('admin.schools.edit', $s) }}"><i class="bi bi-pencil-square text-primary"></i>Edit</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="POST" action="{{ route('admin.schools.destroy', $s) }}" data-confirm="Delete {{ $s->name }} ({{ $s->udise_code }})? This cannot be undone. It works only if the school has no inspection or schedule.">
                                            @csrf @method('DELETE')
                                            <button class="dropdown-item text-danger"><i class="bi bi-trash"></i>Delete</button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-empty-state icon="bi-building" title="No schools found" text="{{ $filtered ? 'No school matches these filters.' : 'Upload the School Master from Excel to get started.' }}" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $schools->links() }}</div>
    </div>
</x-layouts.app>
