@php
    $addOld = fn ($f, $d = null) => old('_edit') ? $d : old($f, $d); // a failed Edit must not fill the Add form
    $editModal = function ($id) use ($errors) {
        $mine = (string) old('_edit') === (string) $id;

        return [$mine, fn ($f, $d) => $mine ? old($f, $d) : $d, $mine ? $errors->edit : new \Illuminate\Support\MessageBag];
    };
@endphp
<x-layouts.app title="AC / PC Master">
    <ul class="nav nav-pills gap-2 mb-3 flex-nowrap overflow-auto pb-1" aria-label="Constituency list">
        <li class="nav-item"><a class="nav-link text-nowrap {{ $tab === 'ac' ? 'active' : 'bg-white border' }}" href="{{ route('admin.constituencies.index') }}">Assembly (AC) <span class="badge {{ $tab === 'ac' ? 'bg-white text-primary' : 'text-bg-light' }} ms-1">{{ $acTotal }}</span></a></li>
        <li class="nav-item"><a class="nav-link text-nowrap {{ $tab === 'pc' ? 'active' : 'bg-white border' }}" href="{{ route('admin.constituencies.index', ['tab' => 'pc']) }}">Parliamentary (PC) <span class="badge {{ $tab === 'pc' ? 'bg-white text-primary' : 'text-bg-light' }} ms-1">{{ $pcs->count() }}</span></a></li>
    </ul>

    <div class="row g-4">
        <div class="col-lg-8">
            @if ($tab === 'ac')
                <form method="GET" class="filter-bar mb-3 row g-2">
                    <div class="col-sm"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Search AC by name or code"></div>
                    <div class="col-sm-5">
                        <select name="pc" class="form-select" aria-label="Filter by PC">
                            <option value="">All PCs</option>
                            @foreach ($pcs as $p)<option value="{{ $p->id }}" @selected($pcFilter === $p->id)>{{ $p->label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-auto"><button class="btn btn-primary btn-icon" aria-label="Search"><i class="bi bi-search"></i></button></div>
                </form>

                <div class="card">
                    @if ($acs->isEmpty())
                        <x-empty-state icon="bi-bank" title="No AC found" text="Add one with the form on this page, or upload the School Master." />
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light"><tr><th>AC code</th><th>AC name</th><th>PC</th><th class="text-end">Schools</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr></thead>
                                <tbody>
                                    @foreach ($acs as $a)
                                        <tr class="{{ $a->is_active ? '' : 'text-secondary' }}">
                                            <td class="font-monospace text-nowrap">{{ $a->code }}</td>
                                            <td class="fw-semibold">{{ $a->name }}@unless ($a->is_active) <span class="badge-soft badge-soft-secondary ms-1">Inactive</span>@endunless</td>
                                            <td class="text-sm">{{ $a->pc?->name }} <span class="font-monospace text-secondary">{{ $a->pc?->code }}</span></td>
                                            <td class="text-end">{{ number_format($a->schools_count) }}</td>
                                            <td class="text-end">
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-light btn-icon" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-label="Actions for {{ $a->name }}"><i class="bi bi-three-dots-vertical"></i></button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 row-actions">
                                                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#ac-edit-{{ $a->id }}"><i class="bi bi-pencil-square text-primary"></i>Edit</button></li>
                                                        <li><form method="POST" action="{{ route('admin.constituencies.ac.update', $a) }}">@csrf @method('PUT')<input type="hidden" name="toggle" value="1">
                                                            <button class="dropdown-item {{ $a->is_active ? 'text-danger' : 'text-success' }}"><i class="bi {{ $a->is_active ? 'bi-slash-circle' : 'bi-check-circle' }}"></i>{{ $a->is_active ? 'Make inactive' : 'Make active' }}</button></form></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="POST" action="{{ route('admin.constituencies.ac.destroy', $a) }}" data-confirm="Delete {{ $a->name }}? This cannot be undone. It works only if nothing uses it.">
                                            @csrf @method('DELETE')
                                            <button class="dropdown-item text-danger"><i class="bi bi-trash"></i>Delete</button>
                                        </form>
                                    </li>

                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
                <div class="mt-3">{{ $acs->links() }}</div>

                @foreach ($acs as $a)
                    @php [$mine, $v, $err] = $editModal($a->id); @endphp
                    <div class="modal fade" id="ac-edit-{{ $a->id }}" tabindex="-1" aria-labelledby="ac-edit-title-{{ $a->id }}" aria-hidden="true" @if ($mine && $errors->edit->any()) data-reopen @endif>
                        <div class="modal-dialog modal-dialog-centered">
                            <form method="POST" action="{{ route('admin.constituencies.ac.update', $a) }}" class="modal-content">
                                @csrf @method('PUT')<input type="hidden" name="_edit" value="{{ $a->id }}">
                                <div class="modal-header"><h5 class="modal-title" id="ac-edit-title-{{ $a->id }}"><i class="bi bi-pencil-square text-primary me-2"></i>Edit AC</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                                <div class="modal-body">
                                    @if ($err->any())<div class="alert alert-danger py-2 text-sm">{{ $err->first() }}</div>@endif
                                    <div class="row g-3">
                                        <div class="col-sm-4"><label class="form-label">AC code</label><input name="code" value="{{ $v('code', $a->code) }}" class="form-control font-monospace {{ $err->has('code') ? 'is-invalid' : '' }}" maxlength="10" required></div>
                                        <div class="col-sm-8"><label class="form-label">AC name</label><input name="name" value="{{ $v('name', $a->name) }}" class="form-control {{ $err->has('name') ? 'is-invalid' : '' }}" maxlength="60" required></div>
                                        <div class="col-12"><label class="form-label">PC</label>
                                            <select name="pc_id" class="form-select" required>@foreach ($pcs as $p)<option value="{{ $p->id }}" @selected((int) $v('pc_id', $a->pc_id) === $p->id)>{{ $p->label }}</option>@endforeach</select>
                                            <div class="form-text">Schools of this AC move to the new PC.</div></div>
                                    </div>
                                </div>
                                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-check-lg"></i>Save changes</button></div>
                            </form>
                        </div>
                    </div>
                @endforeach
            @else
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>PC code</th><th>PC name</th><th class="text-end">ACs</th><th class="text-end">Schools</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr></thead>
                            <tbody>
                                @forelse ($pcs as $p)
                                    <tr class="{{ $p->is_active ? '' : 'text-secondary' }}">
                                        <td class="font-monospace text-nowrap">{{ $p->code }}</td>
                                        <td class="fw-semibold">{{ $p->name }}@unless ($p->is_active) <span class="badge-soft badge-soft-secondary ms-1">Inactive</span>@endunless</td>
                                        <td class="text-end"><a href="{{ route('admin.constituencies.index', ['pc' => $p->id]) }}">{{ $p->acs_count }}</a></td>
                                        <td class="text-end">{{ number_format($p->schools_count) }}</td>
                                        <td class="text-end">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-light btn-icon" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-label="Actions for {{ $p->name }}"><i class="bi bi-three-dots-vertical"></i></button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 row-actions">
                                                    <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#pc-edit-{{ $p->id }}"><i class="bi bi-pencil-square text-primary"></i>Edit</button></li>
                                                    <li><form method="POST" action="{{ route('admin.constituencies.pc.update', $p) }}">@csrf @method('PUT')<input type="hidden" name="toggle" value="1">
                                                        <button class="dropdown-item {{ $p->is_active ? 'text-danger' : 'text-success' }}"><i class="bi {{ $p->is_active ? 'bi-slash-circle' : 'bi-check-circle' }}"></i>{{ $p->is_active ? 'Make inactive' : 'Make active' }}</button></form></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="POST" action="{{ route('admin.constituencies.pc.destroy', $p) }}" data-confirm="Delete {{ $p->name }}? This cannot be undone. It works only if nothing uses it.">
                                            @csrf @method('DELETE')
                                            <button class="dropdown-item text-danger"><i class="bi bi-trash"></i>Delete</button>
                                        </form>
                                    </li>

                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5"><x-empty-state icon="bi-bank" title="No PC yet" text="Add one with the form on this page." /></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @foreach ($pcs as $p)
                    @php [$mine, $v, $err] = $editModal($p->id); @endphp
                    <div class="modal fade" id="pc-edit-{{ $p->id }}" tabindex="-1" aria-labelledby="pc-edit-title-{{ $p->id }}" aria-hidden="true" @if ($mine && $errors->edit->any()) data-reopen @endif>
                        <div class="modal-dialog modal-dialog-centered">
                            <form method="POST" action="{{ route('admin.constituencies.pc.update', $p) }}" class="modal-content">
                                @csrf @method('PUT')<input type="hidden" name="_edit" value="{{ $p->id }}">
                                <div class="modal-header"><h5 class="modal-title" id="pc-edit-title-{{ $p->id }}"><i class="bi bi-pencil-square text-primary me-2"></i>Edit PC</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                                <div class="modal-body">
                                    @if ($err->any())<div class="alert alert-danger py-2 text-sm">{{ $err->first() }}</div>@endif
                                    <div class="row g-3">
                                        <div class="col-sm-4"><label class="form-label">PC code</label><input name="code" value="{{ $v('code', $p->code) }}" class="form-control font-monospace {{ $err->has('code') ? 'is-invalid' : '' }}" maxlength="10" required></div>
                                        <div class="col-sm-8"><label class="form-label">PC name</label><input name="name" value="{{ $v('name', $p->name) }}" class="form-control {{ $err->has('name') ? 'is-invalid' : '' }}" maxlength="60" required></div>
                                    </div>
                                </div>
                                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-check-lg"></i>Save changes</button></div>
                            </form>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card card-elevated">
                <div class="card-header"><i class="bi bi-plus-circle me-2 text-primary"></i>Add {{ $tab === 'ac' ? 'AC (Assembly constituency)' : 'PC (Parliamentary constituency)' }}</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.constituencies.store', $tab) }}">
                        @csrf
                        <label class="form-label">{{ strtoupper($tab) }} code</label>
                        <input name="code" value="{{ $addOld('code') }}" maxlength="10" class="form-control font-monospace mb-3 @error('code') is-invalid @enderror" placeholder="{{ $tab === 'ac' ? 'e.g. AC18002' : 'e.g. PC1801' }}" required>
                        @error('code')<div class="invalid-feedback mt-n2 mb-2">{{ $message }}</div>@enderror
                        <label class="form-label">{{ strtoupper($tab) }} name</label>
                        <input name="name" value="{{ $addOld('name') }}" maxlength="60" class="form-control mb-3 @error('name') is-invalid @enderror" placeholder="{{ $tab === 'ac' ? 'e.g. DOTMA (ST)' : 'e.g. KOKRAJHAR (ST)' }}" required>
                        @error('name')<div class="invalid-feedback mt-n2 mb-2">{{ $message }}</div>@enderror
                        @if ($tab === 'ac')
                            <label class="form-label">PC</label>
                            <select name="pc_id" class="form-select mb-3 @error('pc_id') is-invalid @enderror" required>
                                <option value="">Select</option>
                                @foreach ($pcs->where('is_active', true) as $p)<option value="{{ $p->id }}" @selected((int) $addOld('pc_id', $pcFilter) === $p->id)>{{ $p->label }}</option>@endforeach
                            </select>
                            @error('pc_id')<div class="invalid-feedback mt-n2 mb-2">{{ $message }}</div>@enderror
                        @endif
                        <button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i>Add</button>
                    </form>
                </div>
            </div>
            <div class="alert alert-light border text-sm mt-3"><i class="bi bi-info-circle me-1"></i>The School Master Excel uses the <b>ac</b> and <b>pc</b> codes from this master (the template has an AC list and a PC list sheet). The raw UDISE+ file (<code>Assembly_Name</code>, <code>LS</code>) is also read. An AC or PC that is not in the master is added during the upload. Records are not deleted: make them inactive instead.</div>
        </div>
    </div>
</x-layouts.app>
