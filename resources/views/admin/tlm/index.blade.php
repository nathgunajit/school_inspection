@php
    use App\Http\Controllers\Admin\TlmItemController as TC;
    $tabs = ['' => 'All'] + collect($classes)->filter(fn ($l, $k) => isset($counts[$k]))->all() + [TC::ALL => 'All classes'];
@endphp
<x-layouts.app title="TLM Master">
    <ul class="nav nav-pills gap-2 mb-3 flex-nowrap overflow-auto pb-1">
        @foreach ($tabs as $k => $l)
            @php $k = (string) $k; $n = $k === '' ? $total : ($counts[$k] ?? 0); @endphp
            <li class="nav-item"><a class="nav-link text-nowrap {{ $class === $k ? 'active' : 'bg-white border' }}" href="{{ route('admin.tlm.index', $k === '' ? [] : ['class' => $k]) }}">{{ $l }} <span class="badge {{ $class === $k ? 'bg-white text-primary' : 'text-bg-light' }} ms-1">{{ $n }}</span></a></li>
        @endforeach
    </ul>

    <div class="row g-4">
        <div class="col-lg-8">
            <form method="GET" class="filter-bar mb-3 row g-2">
                @if ($class !== '')<input type="hidden" name="class" value="{{ $class }}">@endif
                <div class="col"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Search TLM by name or code"></div>
                <div class="col-auto"><button class="btn btn-primary btn-icon" aria-label="Search"><i class="bi bi-search"></i></button></div>
            </form>

            <div class="card">
                <div class="list-card">
                    @forelse ($items as $item)
                        <div class="list-item">
                            <span class="badge text-bg-light text-nowrap">{{ $item->classLabel() }}</span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold text-truncate">{{ $item->name }}</div>
                                <div class="text-xs text-secondary">
                                    @if ($item->code)<span class="font-monospace">{{ $item->code }}</span> · @endif
                                    Variety {{ $item->variety }}@if ($item->remarks) · {{ $item->remarks }}@endif
                                </div>
                            </div>
                            <span class="badge-soft badge-soft-{{ $item->is_active ? 'success' : 'secondary' }}">{{ $item->is_active ? 'Active' : 'Inactive' }}</span>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light btn-icon" data-bs-toggle="dropdown" aria-label="Actions"><i class="bi bi-three-dots-vertical"></i></button>
                                <div class="dropdown-menu dropdown-menu-end p-3 shadow border-0" style="width:300px">
                                    <form method="POST" action="{{ route('admin.tlm.update', $item) }}">
                                        @csrf @method('PUT')
                                        <label class="form-label text-sm">Class</label>
                                        <select name="class" class="form-select mb-2" required>
                                            <option value="{{ TC::ALL }}" @selected($item->class === null)>All classes</option>
                                            @foreach ($classes as $k => $l)<option value="{{ $k }}" @selected($item->class === (string) $k)>{{ $l }}</option>@endforeach
                                        </select>
                                        <label class="form-label text-sm">Name</label><input name="name" value="{{ $item->name }}" class="form-control mb-2" maxlength="150" required>
                                        <label class="form-label text-sm">Serial no / code</label><input name="code" value="{{ $item->code }}" class="form-control mb-2" maxlength="60">
                                        <div class="row g-2 mb-2">
                                            <div class="col-6"><label class="form-label text-sm">Variety</label><input name="variety" value="{{ $item->variety }}" type="number" min="1" class="form-control"></div>
                                            <div class="col-6"><label class="form-label text-sm">Order</label><input name="sort" value="{{ $item->sort }}" type="number" min="0" class="form-control"></div>
                                        </div>
                                        <label class="form-label text-sm">Remarks</label><input name="remarks" value="{{ $item->remarks }}" class="form-control mb-2" maxlength="200">
                                        <button class="btn btn-primary btn-sm w-100">Save</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.tlm.update', $item) }}" class="mt-2">
                                        @csrf @method('PUT')<input type="hidden" name="toggle" value="1">
                                        <button class="btn btn-sm w-100 {{ $item->is_active ? 'btn-soft-danger' : 'btn-success' }}">{{ $item->is_active ? 'Make inactive' : 'Make active' }}</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @empty
                        <x-empty-state icon="bi-box-seam" title="No TLM items found" text="Add one with the form on this page." />
                    @endforelse
                </div>
            </div>
            <div class="mt-3">{{ $items->links() }}</div>
        </div>

        <div class="col-lg-4">
            <div class="card card-elevated">
                <div class="card-header"><i class="bi bi-plus-circle me-2 text-primary"></i>Add TLM item</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.tlm.store') }}">
                        @csrf
                        @php $pick = old('class', array_key_exists($class, $classes) ? $class : ''); @endphp
                        <label class="form-label">Class</label>
                        <select name="class" class="form-select mb-3 @error('class') is-invalid @enderror" required>
                            <option value="">Select</option>
                            <option value="{{ TC::ALL }}" @selected($pick === TC::ALL)>All classes</option>
                            @foreach ($classes as $k => $l)<option value="{{ $k }}" @selected($pick === (string) $k)>{{ $l }}</option>@endforeach
                        </select>
                        @error('class')<div class="invalid-feedback mt-n2 mb-2">{{ $message }}</div>@enderror
                        <label class="form-label">Name of item</label>
                        <input name="name" value="{{ old('name') }}" maxlength="150" class="form-control mb-3 @error('name') is-invalid @enderror" placeholder="e.g. Karjyapatra (Literacy)" required>
                        @error('name')<div class="invalid-feedback mt-n2 mb-2">{{ $message }}</div>@enderror
                        <label class="form-label">Serial no / code <span class="opt">optional</span></label>
                        <input name="code" value="{{ old('code') }}" maxlength="60" class="form-control mb-3" placeholder="e.g. NIPUN AXOM G1L4">
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label">Variety</label><input name="variety" value="{{ old('variety', 1) }}" type="number" min="1" class="form-control"></div>
                            <div class="col-6"><label class="form-label">Order</label><input name="sort" value="{{ old('sort', 0) }}" type="number" min="0" class="form-control"></div>
                        </div>
                        <label class="form-label">Remarks <span class="opt">optional</span></label>
                        <input name="remarks" value="{{ old('remarks') }}" maxlength="200" class="form-control mb-3" placeholder="e.g. One copy per child">
                        <button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i>Add</button>
                    </form>
                </div>
            </div>
            <div class="alert alert-light border text-sm mt-3"><i class="bi bi-info-circle me-1"></i>Officers see these items on Step 4 (Classroom performance) when "Use of TLM by teacher" is Yes, for the class observed. Items are not deleted: make them inactive instead, so older reports keep them.</div>
        </div>
    </div>
</x-layouts.app>
