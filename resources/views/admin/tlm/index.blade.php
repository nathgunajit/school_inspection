@php
    use App\Http\Controllers\Admin\TlmItemController as TC;
    $tabs = ['' => 'All'] + collect($classes)->filter(fn ($l, $k) => isset($counts[$k]))->all() + [TC::ALL => 'All classes'];
    $keepList = $list !== '' ? ['list' => $list] : [];
    $addOld = fn ($f, $d = null) => old('_edit') ? $d : old($f, $d); // a failed Edit must not fill the Add form
@endphp
<x-layouts.app title="TLM Master">
    {{-- Modification V10: two lists, FLN and ECCE --}}
    <ul class="nav nav-pills gap-2 mb-2 flex-nowrap overflow-auto pb-1" aria-label="TLM list">
        @foreach (['' => 'All lists'] + $lists as $k => $l)
            @php $k = (string) $k; $n = $k === '' ? $listCounts->sum() : ($listCounts[$k] ?? 0); @endphp
            <li class="nav-item"><a class="nav-link text-nowrap {{ $list === $k ? 'active' : 'bg-white border' }}" href="{{ route('admin.tlm.index', $k === '' ? [] : ['list' => $k]) }}">{{ $l }} <span class="badge {{ $list === $k ? 'bg-white text-primary' : 'text-bg-light' }} ms-1">{{ $n }}</span></a></li>
        @endforeach
    </ul>
    <ul class="nav nav-pills gap-2 mb-3 flex-nowrap overflow-auto pb-1" aria-label="Class">
        @foreach ($tabs as $k => $l)
            @php $k = (string) $k; $n = $k === '' ? $total : ($counts[$k] ?? 0); @endphp
            <li class="nav-item"><a class="nav-link text-nowrap {{ $class === $k ? 'active' : 'bg-white border' }}" href="{{ route('admin.tlm.index', $keepList + ($k === '' ? [] : ['class' => $k])) }}">{{ $l }} <span class="badge {{ $class === $k ? 'bg-white text-primary' : 'text-bg-light' }} ms-1">{{ $n }}</span></a></li>
        @endforeach
    </ul>

    <div class="row g-4">
        <div class="col-lg-8">
            <form method="GET" class="filter-bar mb-3 row g-2">
                @if ($list !== '')<input type="hidden" name="list" value="{{ $list }}">@endif
                @if ($class !== '')<input type="hidden" name="class" value="{{ $class }}">@endif
                <div class="col"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Search TLM by name or code"></div>
                <div class="col-auto"><button class="btn btn-primary btn-icon" aria-label="Search"><i class="bi bi-search"></i></button></div>
            </form>

            <div class="card">
                @if ($items->isEmpty())
                    <x-empty-state icon="bi-box-seam" title="No TLM items found" text="Add one with the form on this page." />
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 tlm-table">
                            <thead class="table-light">
                                <tr><th style="width:3rem">Sl.</th><th>List</th><th>Class / group</th><th>Name of item</th><th>Serial no / Code</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr>
                            </thead>
                            <tbody>
                                @foreach ($items as $item)
                                    <tr class="{{ $item->is_active ? '' : 'text-secondary' }}">
                                        <td class="text-secondary">{{ $items->firstItem() + $loop->index }}</td>
                                        <td class="text-nowrap"><span class="badge tlm-list-badge is-{{ $item->list }}">{{ $item->listShort() }}</span></td>
                                        <td class="text-nowrap"><span class="badge text-bg-light">{{ $item->classLabel() }}</span>@if ($item->section)<div class="text-xs text-secondary mt-1">{{ $item->section }}</div>@endif</td>
                                        <td class="fw-semibold">{{ $item->name }}@unless ($item->is_active) <span class="badge-soft badge-soft-secondary ms-1">Inactive</span>@endunless
                                            @if ($item->remarks)<div class="text-xs text-secondary fw-normal">{{ $item->remarks }}</div>@endif</td>
                                        <td class="font-monospace text-nowrap">{{ $item->code ?: 'NA' }}</td>
                                        <td class="text-end">
                                            {{-- V12 item 7: the three-dot menu holds only actions; Edit opens a window (the form no longer sits inside the dropdown) --}}
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-light btn-icon" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-label="Actions for {{ $item->name }}"><i class="bi bi-three-dots-vertical"></i></button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 row-actions">
                                                    <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#tlm-edit-{{ $item->id }}"><i class="bi bi-pencil-square text-primary"></i>Edit</button></li>
                                                    <li>
                                                        <form method="POST" action="{{ route('admin.tlm.update', $item) }}">
                                                            @csrf @method('PUT')<input type="hidden" name="toggle" value="1">
                                                            <button class="dropdown-item {{ $item->is_active ? 'text-danger' : 'text-success' }}"><i class="bi {{ $item->is_active ? 'bi-slash-circle' : 'bi-check-circle' }}"></i>{{ $item->is_active ? 'Make inactive' : 'Make active' }}</button>
                                                        </form>
                                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="POST" action="{{ route('admin.tlm.destroy', $item) }}" data-confirm="Delete {{ $item->name }}? This cannot be undone. It works only if nothing uses it.">
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
            <div class="mt-3">{{ $items->links() }}</div>
            {{-- V12 item 7: one Edit window per row, outside the table so nothing clips or closes it --}}
            @foreach ($items as $item)
                @php
                    $mine = (string) old('_edit') === (string) $item->id;
                    $v = fn ($f, $d) => $mine ? old($f, $d) : $d;
                    $err = $mine ? $errors->edit : new \Illuminate\Support\MessageBag;
                    $cls = $item->class === null ? TC::ALL : (string) $item->class;
                @endphp
                <div class="modal fade" id="tlm-edit-{{ $item->id }}" tabindex="-1" aria-labelledby="tlm-edit-title-{{ $item->id }}" aria-hidden="true" @if ($mine && $errors->edit->any()) data-reopen @endif>
                    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                        <form method="POST" action="{{ route('admin.tlm.update', $item) }}" class="modal-content">
                            @csrf @method('PUT')
                            <input type="hidden" name="_edit" value="{{ $item->id }}">
                            <div class="modal-header">
                                <h5 class="modal-title" id="tlm-edit-title-{{ $item->id }}"><i class="bi bi-pencil-square text-primary me-2"></i>Edit TLM item</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                @if ($err->any())<div class="alert alert-danger py-2 text-sm">{{ $err->first() }}</div>@endif
                                <div class="row g-3">
                                    <div class="col-sm-6"><label class="form-label">List</label>
                                        <select name="list" class="form-select" required>
                                            @foreach ($lists as $k => $l)<option value="{{ $k }}" @selected($v('list', $item->list) === $k)>{{ $l }}</option>@endforeach
                                        </select></div>
                                    <div class="col-sm-6"><label class="form-label">Class</label>
                                        <select name="class" class="form-select {{ $err->has('class') ? 'is-invalid' : '' }}" required>
                                            <option value="{{ TC::ALL }}" @selected($v('class', $cls) === TC::ALL)>All classes</option>
                                            @foreach ($classes as $k => $l)<option value="{{ $k }}" @selected((string) $v('class', $cls) === (string) $k)>{{ $l }}</option>@endforeach
                                        </select></div>
                                    <div class="col-12"><label class="form-label">Name of item</label>
                                        <input name="name" value="{{ $v('name', $item->name) }}" class="form-control {{ $err->has('name') ? 'is-invalid' : '' }}" maxlength="150" required>
                                        @if ($err->has('name'))<div class="invalid-feedback">{{ $err->first('name') }}</div>@endif</div>
                                    <div class="col-sm-6"><label class="form-label">Group <span class="opt">optional</span></label>
                                        <input name="section" value="{{ $v('section', $item->section) }}" class="form-control" maxlength="60" placeholder="e.g. TLM for schools"></div>
                                    <div class="col-sm-6"><label class="form-label">Serial no / code <span class="opt">optional</span></label>
                                        <input name="code" value="{{ $v('code', $item->code) }}" class="form-control" maxlength="60"></div>
                                    <div class="col-6"><label class="form-label">Variety</label><input name="variety" value="{{ $v('variety', $item->variety) }}" type="number" min="1" class="form-control"></div>
                                    <div class="col-6"><label class="form-label">Order</label><input name="sort" value="{{ $v('sort', $item->sort) }}" type="number" min="0" class="form-control"></div>
                                    <div class="col-12"><label class="form-label">Remarks <span class="opt">optional</span></label>
                                        <input name="remarks" value="{{ $v('remarks', $item->remarks) }}" class="form-control" maxlength="200"></div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                <button class="btn btn-primary"><i class="bi bi-check-lg"></i>Save changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach

        </div>

        <div class="col-lg-4">
            <div class="card card-elevated">
                <div class="card-header"><i class="bi bi-plus-circle me-2 text-primary"></i>Add TLM item</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.tlm.store') }}">
                        @csrf
                        @php $pick = $addOld('class', array_key_exists($class, $classes) ? $class : ''); $pickList = $addOld('list', $list !== '' ? $list : 'fln'); @endphp
                        <label class="form-label">List</label>
                        <select name="list" class="form-select mb-3 @error('list') is-invalid @enderror" required>
                            @foreach ($lists as $k => $l)<option value="{{ $k }}" @selected($pickList === $k)>{{ $l }}</option>@endforeach
                        </select>
                        @error('list')<div class="invalid-feedback mt-n2 mb-2">{{ $message }}</div>@enderror
                        <label class="form-label">Class</label>
                        <select name="class" class="form-select mb-3 @error('class') is-invalid @enderror" required>
                            <option value="">Select</option>
                            <option value="{{ TC::ALL }}" @selected($pick === TC::ALL)>All classes</option>
                            @foreach ($classes as $k => $l)<option value="{{ $k }}" @selected($pick === (string) $k)>{{ $l }}</option>@endforeach
                        </select>
                        @error('class')<div class="invalid-feedback mt-n2 mb-2">{{ $message }}</div>@enderror
                        <label class="form-label">Group <span class="opt">optional</span></label>
                        <input name="section" value="{{ $addOld('section') }}" maxlength="60" class="form-control mb-3" placeholder="e.g. TLM for schools (ECCE)">
                        <label class="form-label">Name of item</label>
                        <input name="name" value="{{ $addOld('name') }}" maxlength="150" class="form-control mb-3 @error('name') is-invalid @enderror" placeholder="e.g. Karjyapatra (Literacy)" required>
                        @error('name')<div class="invalid-feedback mt-n2 mb-2">{{ $message }}</div>@enderror
                        <label class="form-label">Serial no / code <span class="opt">optional</span></label>
                        <input name="code" value="{{ $addOld('code') }}" maxlength="60" class="form-control mb-3" placeholder="e.g. NIPUN AXOM G1L4">
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label">Variety</label><input name="variety" value="{{ $addOld('variety', 1) }}" type="number" min="1" class="form-control"></div>
                            <div class="col-6"><label class="form-label">Order</label><input name="sort" value="{{ $addOld('sort', 0) }}" type="number" min="0" class="form-control"></div>
                        </div>
                        <label class="form-label">Remarks <span class="opt">optional</span></label>
                        <input name="remarks" value="{{ $addOld('remarks') }}" maxlength="200" class="form-control mb-3" placeholder="e.g. One copy per child">
                        <button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i>Add</button>
                    </form>
                </div>
            </div>
            <div class="alert alert-light border text-sm mt-3"><i class="bi bi-info-circle me-1"></i>Officers see the FLN and the ECCE lists on Step 4 (Classroom performance) when "Use of TLM by teacher" is Yes. FLN items are grouped by class, ECCE items by group. Items are not deleted: make them inactive instead, so older reports keep them.</div>
        </div>
    </div>
</x-layouts.app>
