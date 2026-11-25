{{-- 25-11-2026: Notices & Orders letter types master. Add / Edit / Make inactive; never deleted, the code never changes. --}}
@php
    use App\Models\NoticeType;
    $addOld = fn ($f, $d = null) => old('_edit') ? $d : old($f, $d); // a failed Edit must not fill the Add form
@endphp
<x-layouts.app title="Notice Types">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-tags text-primary"></i>Notice &amp; order types
                    <span class="text-xs text-secondary ms-auto">{{ $types->where('is_active', true)->count() }} active of {{ $types->count() }}</span></div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th style="width:3rem">Sl.</th><th>Type</th><th>Code</th><th class="text-end">Notices</th><th>Status</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr></thead>
                        <tbody>
                            @foreach ($types as $t)
                                <tr class="{{ $t->is_active ? '' : 'text-secondary' }}">
                                    <td class="text-secondary">{{ $loop->iteration }}</td>
                                    <td><span class="notice-type" style="--nt: {{ $t->color }}"><i class="bi {{ $t->icon }} me-1"></i>{{ $t->name }}</span></td>
                                    <td class="font-monospace text-xs">{{ $t->code }}</td>
                                    <td class="text-end">{{ number_format($used[$t->code] ?? 0) }}</td>
                                    <td>@if ($t->is_active)<span class="badge-soft badge-soft-success">Active</span>@else<span class="badge-soft badge-soft-secondary">Inactive</span>@endif</td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-light btn-icon" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-label="Actions for {{ $t->name }}"><i class="bi bi-three-dots-vertical"></i></button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 row-actions">
                                                <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#nt-edit-{{ $t->id }}"><i class="bi bi-pencil-square text-primary"></i>Edit</button></li>
                                                <li>
                                                    <form method="POST" action="{{ route('admin.notice-types.status', $t) }}">
                                                        @csrf @method('PATCH')
                                                        <button class="dropdown-item {{ $t->is_active ? 'text-danger' : 'text-success' }}"><i class="bi {{ $t->is_active ? 'bi-slash-circle' : 'bi-check-circle' }}"></i>{{ $t->is_active ? 'Make inactive' : 'Make active' }}</button>
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
            </div>

            @foreach ($types as $t)
                @php
                    $mine = (string) old('_edit') === (string) $t->id;
                    $v = fn ($f, $d) => $mine ? old($f, $d) : $d;
                    $err = $mine ? $errors->edit : new \Illuminate\Support\MessageBag;
                @endphp
                <div class="modal fade" id="nt-edit-{{ $t->id }}" tabindex="-1" aria-labelledby="nt-edit-title-{{ $t->id }}" aria-hidden="true" @if ($mine && $errors->edit->any()) data-reopen @endif>
                    <div class="modal-dialog modal-dialog-centered">
                        <form method="POST" action="{{ route('admin.notice-types.update', $t) }}" class="modal-content">
                            @csrf @method('PUT')
                            <input type="hidden" name="_edit" value="{{ $t->id }}">
                            <div class="modal-header">
                                <h5 class="modal-title" id="nt-edit-title-{{ $t->id }}"><i class="bi bi-pencil-square text-primary me-2"></i>Edit notice type</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                @if ($err->any())<div class="alert alert-danger py-2 text-sm">{{ $err->first() }}</div>@endif
                                @include('admin.notice-types.fields', ['val' => $v, 'type' => $t, 'idp' => 'e'.$t->id, 'err' => $err])
                                <div class="form-text mt-2">Code <code>{{ $t->code }}</code> stays the same, so existing notices keep this type.</div>
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
                <div class="card-header"><i class="bi bi-plus-circle me-2 text-primary"></i>Add notice type</div>
                <form method="POST" action="{{ route('admin.notice-types.store') }}" class="card-body">
                    @csrf
                    @include('admin.notice-types.fields', ['val' => fn ($f, $d) => $addOld($f, $d), 'type' => null, 'idp' => 'a', 'err' => old('_edit') ? new \Illuminate\Support\MessageBag : $errors])
                    <button class="btn btn-primary w-100 mt-3"><i class="bi bi-plus-lg"></i>Add</button>
                </form>
            </div>
            <div class="alert alert-light border text-sm mt-3"><i class="bi bi-info-circle me-1"></i>Active types are offered when writing a notice or order. Types are not deleted: make one inactive instead, so notices already sent keep their type.</div>
        </div>
    </div>
</x-layouts.app>
