<x-layouts.app title="Location Master">
    <ul class="nav nav-pills gap-2 mb-3 flex-nowrap overflow-auto pb-1">
        @foreach (['states' => 'States', 'districts' => 'Districts', 'blocks' => 'Blocks', 'clusters' => 'Clusters'] as $t => $l)
            <li class="nav-item"><a class="nav-link {{ $type === $t ? 'active' : 'bg-white border' }}" href="{{ route('admin.locations.index', $t) }}">{{ $l }} <span class="badge {{ $type === $t ? 'bg-white text-primary' : 'text-bg-light' }} ms-1">{{ $counts[$t] }}</span></a></li>
        @endforeach
    </ul>

    <div class="row g-4">
        <div class="col-lg-8">
            <form method="GET" class="filter-bar mb-3 row g-2">
                <div class="col"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Search {{ strtolower($label) }} by name or code"></div>
                @if ($fk)
                    <div class="col-12 col-sm-5">
                        <select name="parent" class="form-select" data-autosubmit aria-label="Parent">
                            <option value="">All</option>
                            @foreach ($parents as $p)<option value="{{ $p->id }}" @selected(request('parent') == $p->id)>{{ $p->name }}</option>@endforeach
                        </select>
                    </div>
                @endif
                <div class="col-auto"><button class="btn btn-primary btn-icon" aria-label="Search"><i class="bi bi-search"></i></button></div>
            </form>

            <div class="card">
                <div class="list-card">
                    @forelse ($items as $item)
                        <div class="list-item">
                            <span class="badge text-bg-light font-monospace">{{ $item->code }}</span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold text-truncate">{{ $item->name }}</div>
                                @if ($fk)<div class="text-xs text-secondary">{{ $item->{str_replace('_id', '', $fk)}?->name }}</div>@endif
                            </div>
                            <span class="badge-soft badge-soft-{{ $item->is_active ? 'success' : 'secondary' }}">{{ $item->is_active ? 'Active' : 'Inactive' }}</span>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light btn-icon" data-bs-toggle="dropdown" aria-label="Actions"><i class="bi bi-three-dots-vertical"></i></button>
                                <div class="dropdown-menu dropdown-menu-end p-3 shadow border-0" style="width:300px">
                                    <form method="POST" action="{{ route('admin.locations.update', [$type, $item->id]) }}">
                                        @csrf @method('PUT')
                                        <label class="form-label text-sm">Code</label><input name="code" value="{{ $item->code }}" class="form-control mb-2" required>
                                        <label class="form-label text-sm">Name</label><input name="name" value="{{ $item->name }}" class="form-control mb-2" required>
                                        @if ($fk)<input type="hidden" name="{{ $fk }}" value="{{ $item->$fk }}">@endif
                                        <button class="btn btn-primary btn-sm w-100">Save</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.locations.update', [$type, $item->id]) }}" class="mt-2">
                                        @csrf @method('PUT')<input type="hidden" name="toggle" value="1">
                                        <button class="btn btn-sm w-100 {{ $item->is_active ? 'btn-soft-danger' : 'btn-success' }}">{{ $item->is_active ? 'Make inactive' : 'Make active' }}</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @empty
                        <x-empty-state icon="bi-geo-alt" title="No {{ strtolower($label) }}s found" text="Add one here or upload the Location Master from Excel." />
                    @endforelse
                </div>
            </div>
            <div class="mt-3">{{ $items->links() }}</div>
        </div>

        <div class="col-lg-4">
            <div class="card card-elevated">
                <div class="card-header"><i class="bi bi-plus-circle me-2 text-primary"></i>Add {{ strtolower($label) }}</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.locations.store', $type) }}">
                        @csrf
                        @if ($fk)
                            <label class="form-label">Parent</label>
                            <select name="{{ $fk }}" class="form-select mb-3 @error($fk) is-invalid @enderror" required>
                                <option value="">Select</option>
                                @foreach ($parents as $p)<option value="{{ $p->id }}" @selected(old($fk) == $p->id)>{{ $p->name }} ({{ $p->code }})</option>@endforeach
                            </select>
                        @endif
                        <label class="form-label">Official code</label>
                        <input name="code" value="{{ old('code') }}" inputmode="numeric" class="form-control mb-3 @error('code') is-invalid @enderror" required>
                        @error('code')<div class="invalid-feedback mt-n2 mb-2">{{ $message }}</div>@enderror
                        <label class="form-label">Name</label>
                        <input name="name" value="{{ old('name') }}" class="form-control mb-3 @error('name') is-invalid @enderror" required>
                        <button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i>Add</button>
                    </form>
                </div>
            </div>
            <div class="alert alert-light border text-sm mt-3"><i class="bi bi-info-circle me-1"></i>Locations that have schools or users cannot be deleted. Make them inactive instead.</div>
        </div>
    </div>
</x-layouts.app>
