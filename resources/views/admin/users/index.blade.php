<x-layouts.app title="User List">
    <form method="GET" class="filter-bar mb-3">
        <div class="row g-2">
            <div class="col-12 col-md-3">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Name, mobile or employee ID">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="level" class="form-select" data-autosubmit aria-label="Level">
                    <option value="">All levels</option>
                    @foreach ($levels as $l)<option value="{{ $l->value }}" @selected(($filters['level'] ?? '') === $l->value)>{{ $l->label() }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select" data-autosubmit aria-label="Status">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $s)<option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ $s->label() }}</option>@endforeach
                </select>
            </div>
            @if (auth()->user()->level->isStateWide())
                <div class="col-6 col-md-2">
                    <select name="district_id" class="form-select" data-autosubmit aria-label="District" onchange="this.form.block_id && (this.form.block_id.value='')">
                        <option value="">All districts</option>
                        @foreach ($districts as $d)<option value="{{ $d->id }}" @selected(($filters['district_id'] ?? '') == $d->id)>{{ $d->name }}</option>@endforeach
                    </select>
                </div>
            @endif
            @if ($blocks->isNotEmpty())
                <div class="col-6 col-md-2">
                    <select name="block_id" class="form-select" data-autosubmit aria-label="Block">
                        <option value="">All blocks</option>
                        @foreach ($blocks as $b)<option value="{{ $b->id }}" @selected(($filters['block_id'] ?? '') == $b->id)>{{ $b->name }}</option>@endforeach
                    </select>
                </div>
            @endif
            <div class="col-4 col-md-1 d-grid"><button class="btn btn-primary"><i class="bi bi-funnel"></i><span class="d-none d-xl-inline">Go</span></button></div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive d-none d-md-block">
            <table class="table align-middle mb-0">
                <thead><tr><th>Officer</th><th>Level</th><th>Jurisdiction</th><th>Mobile</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse ($users as $u)
                    <tr>
                        <td><div class="d-flex align-items-center gap-2"><x-user-avatar :user="$u" /><div><div class="fw-semibold">{{ $u->name }}</div><div class="text-xs text-secondary">{{ $u->designation?->label }}@if ($u->employee_id) · {{ $u->employee_id }}@endif</div></div></div></td>
                        <td class="text-sm">{{ $u->level->label() }}</td>
                        <td class="text-sm">{{ $u->currentJurisdiction?->label() }}</td>
                        <td class="text-sm">{{ $u->mobile }}</td>
                        <td><x-status-badge :status="$u->status" /></td>
                        <td class="text-end"><a href="{{ route('admin.users.show', $u) }}" class="btn btn-sm btn-soft-primary">Manage</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty-state icon="bi-people" title="No users found" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="list-card d-md-none">
            @forelse ($users as $u)
                <a href="{{ route('admin.users.show', $u) }}" class="list-item text-decoration-none">
                    <x-user-avatar :user="$u" />
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate">{{ $u->name }}</div>
                        <div class="text-xs text-secondary text-truncate">{{ $u->level->label() }} · {{ $u->currentJurisdiction?->label() }}</div>
                    </div>
                    <x-status-badge :status="$u->status" />
                </a>
            @empty
                <x-empty-state icon="bi-people" title="No users found" />
            @endforelse
        </div>
    </div>
    <div class="mt-3">{{ $users->links() }}</div>
</x-layouts.app>
