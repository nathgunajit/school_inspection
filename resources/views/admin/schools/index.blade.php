<x-layouts.app title="School Master">
    <x-slot:actions><a href="{{ route('admin.schools.create') }}" class="btn btn-primary d-none d-sm-inline-flex"><i class="bi bi-plus-lg"></i>Add school</a></x-slot:actions>

    <form method="GET" class="filter-bar mb-3 row g-2">
        <div class="col-12 col-md-5"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="UDISE code or school name"></div>
        <div class="col-6 col-md-3">
            <select name="district_id" class="form-select" data-autosubmit aria-label="District"><option value="">All districts</option>
                @foreach ($districts as $d)<option value="{{ $d->id }}" @selected(request('district_id') == $d->id)>{{ $d->name }}</option>@endforeach</select>
        </div>
        <div class="col-6 col-md-2">
            <select name="status" class="form-select" data-autosubmit aria-label="Status"><option value="">Any status</option>
                <option value="active" @selected(request('status') === 'active')>Active</option><option value="closed" @selected(request('status') === 'closed')>Closed</option></select>
        </div>
        <div class="col-12 col-md-2 d-grid"><button class="btn btn-primary"><i class="bi bi-search"></i>Search</button></div>
    </form>
    <a href="{{ route('admin.schools.create') }}" class="btn btn-primary w-100 mb-3 d-sm-none"><i class="bi bi-plus-lg"></i>Add school</a>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>UDISE</th><th>School</th><th class="d-none d-md-table-cell">Block / Cluster</th><th class="d-none d-lg-table-cell">Category</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse ($schools as $s)
                    <tr>
                        <td class="font-monospace text-sm">{{ $s->udise_code }}</td>
                        <td><div class="fw-semibold">{{ $s->name }}</div><div class="text-xs text-secondary">{{ $s->district?->name }}</div></td>
                        <td class="d-none d-md-table-cell text-sm">{{ $s->block?->name }}<div class="text-xs text-secondary">{{ $s->cluster?->name }}</div></td>
                        <td class="d-none d-lg-table-cell text-sm">{{ $s->category?->label }}</td>
                        <td><span class="badge-soft badge-soft-{{ $s->status === 'active' ? 'success' : 'secondary' }}">{{ ucfirst($s->status) }}</span></td>
                        <td class="text-end"><a href="{{ route('admin.schools.edit', $s) }}" class="btn btn-sm btn-light btn-icon" aria-label="Edit"><i class="bi bi-pencil"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty-state icon="bi-building" title="No schools found" text="Upload the School Master from Excel to get started." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $schools->links() }}</div>
</x-layouts.app>
