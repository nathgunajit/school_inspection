@php $addOld = fn ($f, $d = null) => old('_edit') ? $d : old($f, $d); /* a failed Edit must not fill the Add form */ @endphp
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
                                @if ($fk)<div class="text-xs text-secondary">{{ $item->{str_replace('_id', '', $fk)}?->label }}</div>@endif
                            </div>
                            @if ($geo)
                                @if ($item->latitude !== null)
                                    <a href="https://www.google.com/maps?q={{ $item->latitude }},{{ $item->longitude }}" target="_blank" rel="noopener noreferrer"
                                       class="geo-chip d-none d-sm-inline-flex" title="Open in map"><i class="bi bi-geo-alt"></i>{{ number_format($item->latitude, 5) }}, {{ number_format($item->longitude, 5) }}</a>
                                @else
                                    <span class="geo-chip is-empty d-none d-sm-inline-flex"><i class="bi bi-geo"></i>No coordinates</span>
                                @endif
                            @endif
                            @if ($type === 'districts' && $boundaryNames)
                                @php $bn = $boundaries->nameFor($item); @endphp
                                <span class="geo-chip d-none d-md-inline-flex {{ $bn ? '' : 'is-empty' }}" title="Map boundary (KML)"><i class="bi bi-bounding-box"></i>{{ $bn ?? 'No boundary' }}</span>
                            @endif
                            <span class="badge-soft badge-soft-{{ $item->is_active ? 'success' : 'secondary' }}">{{ $item->is_active ? 'Active' : 'Inactive' }}</span>
                            {{-- V12 item 7: the three-dot menu holds only actions; Edit opens a window --}}
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light btn-icon" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-label="Actions for {{ $item->name }}"><i class="bi bi-three-dots-vertical"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 row-actions">
                                    <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#loc-edit-{{ $item->id }}"><i class="bi bi-pencil-square text-primary"></i>Edit</button></li>
                                    <li>
                                        <form method="POST" action="{{ route('admin.locations.update', [$type, \App\Support\UrlId::encode($item::class, $item->id)]) }}">
                                            @csrf @method('PUT')<input type="hidden" name="toggle" value="1">
                                            <button class="dropdown-item {{ $item->is_active ? 'text-danger' : 'text-success' }}"><i class="bi {{ $item->is_active ? 'bi-slash-circle' : 'bi-check-circle' }}"></i>{{ $item->is_active ? 'Make inactive' : 'Make active' }}</button>
                                        </form>
                                    </li>
@if ($type !== 'states')
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="POST" action="{{ route('admin.locations.destroy', [$type, \App\Support\UrlId::encode($item::class, $item->id)]) }}" data-confirm="Delete {{ $item->name }}? This cannot be undone. It works only if nothing uses it.">
                                            @csrf @method('DELETE')
                                            <button class="dropdown-item text-danger"><i class="bi bi-trash"></i>Delete</button>
                                        </form>
                                    </li>
@endif
                                </ul>
                            </div>
                        </div>
                    @empty
                        <x-empty-state icon="bi-geo-alt" title="No {{ strtolower($label) }}s found" text="Add one here or upload the Location Master from Excel." />
                    @endforelse
                </div>
            </div>
            <div class="mt-3">{{ $items->links() }}</div>
            {{-- V12 item 7: one Edit window per row, outside the list so nothing clips or closes it --}}
            @foreach ($items as $item)
                @php
                    $mine = (string) old('_edit') === (string) $item->id;
                    $v = fn ($f, $d) => $mine ? old($f, $d) : $d;
                    $err = $mine ? $errors->edit : new \Illuminate\Support\MessageBag;
                @endphp
                <div class="modal fade" id="loc-edit-{{ $item->id }}" tabindex="-1" aria-labelledby="loc-edit-title-{{ $item->id }}" aria-hidden="true" @if ($mine && $errors->edit->any()) data-reopen @endif>
                    <div class="modal-dialog modal-dialog-centered">
                        <form method="POST" action="{{ route('admin.locations.update', [$type, \App\Support\UrlId::encode($item::class, $item->id)]) }}" class="modal-content">
                            @csrf @method('PUT')
                            <input type="hidden" name="_edit" value="{{ $item->id }}">
                            @if ($fk)<input type="hidden" name="{{ $fk }}" value="{{ $item->$fk }}">@endif
                            <div class="modal-header">
                                <h5 class="modal-title" id="loc-edit-title-{{ $item->id }}"><i class="bi bi-pencil-square text-primary me-2"></i>Edit {{ strtolower($label) }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                @if ($err->any())<div class="alert alert-danger py-2 text-sm">{{ $err->first() }}</div>@endif
                                @if ($fk)<div class="text-sm text-secondary mb-3"><i class="bi bi-diagram-3 me-1"></i>Under {{ $item->{str_replace('_id', '', $fk)}?->name }}</div>@endif
                                <div class="row g-3">
                                    <div class="col-sm-5"><label class="form-label">Official code</label>
                                        <input name="code" value="{{ $v('code', $item->code) }}" inputmode="numeric" class="form-control {{ $err->has('code') ? 'is-invalid' : '' }}" required>
                                        @if ($err->has('code'))<div class="invalid-feedback">{{ $err->first('code') }}</div>@endif</div>
                                    <div class="col-sm-7"><label class="form-label">Name</label>
                                        <input name="name" value="{{ $v('name', $item->name) }}" class="form-control {{ $err->has('name') ? 'is-invalid' : '' }}" required>
                                        @if ($err->has('name'))<div class="invalid-feedback">{{ $err->first('name') }}</div>@endif</div>
                                    @if ($geo)
                                        <div class="col-6"><label class="form-label">Latitude <span class="opt">optional</span></label>
                                            <input name="latitude" value="{{ $v('latitude', $item->latitude) }}" inputmode="decimal" class="form-control {{ $err->has('latitude') ? 'is-invalid' : '' }}" placeholder="26.1445">
                                            @if ($err->has('latitude'))<div class="invalid-feedback">{{ $err->first('latitude') }}</div>@endif</div>
                                        <div class="col-6"><label class="form-label">Longitude <span class="opt">optional</span></label>
                                            <input name="longitude" value="{{ $v('longitude', $item->longitude) }}" inputmode="decimal" class="form-control {{ $err->has('longitude') ? 'is-invalid' : '' }}" placeholder="91.7362">
                                            @if ($err->has('longitude'))<div class="invalid-feedback">{{ $err->first('longitude') }}</div>@endif</div>
                                    @endif
                                    @if ($type === 'districts' && $boundaryNames)
                                        {{-- V6 Module 7: KML outline drawn for this district on the maps --}}
                                        <div class="col-12"><label class="form-label">Map boundary</label>
                                            <select name="boundary_name" class="form-select">
                                                <option value="">Match by name{{ $boundaries->nameFor($item) && ! $item->boundary_name ? ' ('.$boundaries->nameFor($item).')' : '' }}</option>
                                                @foreach ($boundaryNames as $bn)<option value="{{ $bn }}" @selected($v('boundary_name', $item->boundary_name) === $bn)>{{ $bn }}</option>@endforeach
                                            </select></div>
                                    @endif
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
                <div class="card-header"><i class="bi bi-plus-circle me-2 text-primary"></i>Add {{ strtolower($label) }}</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.locations.store', $type) }}">
                        @csrf
                        @if ($fk)
                            <label class="form-label">Parent</label>
                            <select name="{{ $fk }}" class="form-select mb-3 @error($fk) is-invalid @enderror" required>
                                <option value="">Select</option>
                                @foreach ($parents as $p)<option value="{{ $p->id }}" @selected($addOld($fk) == $p->id)>{{ $p->name }} ({{ $p->code }})</option>@endforeach
                            </select>
                        @endif
                        <label class="form-label">Official code</label>
                        <input name="code" value="{{ $addOld('code') }}" inputmode="numeric" class="form-control mb-3 @error('code') is-invalid @enderror" required>
                        @error('code')<div class="invalid-feedback mt-n2 mb-2">{{ $message }}</div>@enderror
                        <label class="form-label">Name</label>
                        <input name="name" value="{{ $addOld('name') }}" class="form-control mb-3 @error('name') is-invalid @enderror" required>
                        @if ($geo)
                            <div class="row g-2 mb-1">
                                <div class="col-6">
                                    <label class="form-label">Latitude <span class="opt">optional</span></label>
                                    <input name="latitude" value="{{ $addOld('latitude') }}" inputmode="decimal" placeholder="26.1445" class="form-control @error('latitude') is-invalid @enderror">
                                    @error('latitude')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Longitude <span class="opt">optional</span></label>
                                    <input name="longitude" value="{{ $addOld('longitude') }}" inputmode="decimal" placeholder="91.7362" class="form-control @error('longitude') is-invalid @enderror">
                                    @error('longitude')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <div class="form-text mb-3"><i class="bi bi-info-circle me-1"></i>Headquarters location in decimal degrees.</div>
                        @endif
                        <button class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i>Add</button>
                    </form>
                </div>
            </div>
            <div class="alert alert-light border text-sm mt-3"><i class="bi bi-info-circle me-1"></i>Locations that have schools or users cannot be deleted. Make them inactive instead.</div>
        </div>
    </div>
</x-layouts.app>
