<x-layouts.app :title="$school->exists ? 'Edit School' : 'Add School'" :back="route('admin.schools.index')">
    <div class="row justify-content-center">
        <div class="col-xl-9">
            <form method="POST" action="{{ $school->exists ? route('admin.schools.update', $school) : route('admin.schools.store') }}" class="card card-elevated">
                @csrf @if ($school->exists) @method('PUT') @endif
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">UDISE Code <span class="text-danger">*</span></label>
                            <input name="udise_code" value="{{ old('udise_code', $school->udise_code) }}" maxlength="11" inputmode="numeric" class="form-control font-monospace @error('udise_code') is-invalid @enderror" required>
                            @error('udise_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">School name <span class="text-danger">*</span></label>
                            <input name="name" value="{{ old('name', $school->name) }}" class="form-control @error('name') is-invalid @enderror" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">District <span class="text-danger">*</span></label>
                            <select name="district_id" id="s_district" class="form-select @error('district_id') is-invalid @enderror" data-cascade-child="#s_block" data-cascade-url="{{ route('locations', 'blocks') }}" required>
                                <option value="">Select</option>@foreach ($districts as $d)<option value="{{ $d->id }}" @selected(old('district_id', $school->district_id) == $d->id)>{{ $d->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Block <span class="text-danger">*</span></label>
                            <select name="block_id" id="s_block" class="form-select @error('block_id') is-invalid @enderror" data-placeholder="Select" data-cascade-child="#s_cluster" data-cascade-url="{{ route('locations', 'clusters') }}" required>
                                <option value="">Select</option>@foreach ($blocks as $b)<option value="{{ $b->id }}" @selected(old('block_id', $school->block_id) == $b->id)>{{ $b->name }}</option>@endforeach
                            </select>
                            @error('block_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Cluster <span class="text-danger">*</span></label>
                            <select name="cluster_id" id="s_cluster" class="form-select @error('cluster_id') is-invalid @enderror" data-placeholder="Select" required>
                                <option value="">Select</option>@foreach ($clusters as $c)<option value="{{ $c->id }}" @selected(old('cluster_id', $school->cluster_id) == $c->id)>{{ $c->name }}</option>@endforeach
                            </select>
                            @error('cluster_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">School management</label>
                            <select name="management_id" class="form-select"><option value="">—</option>@foreach ($managements as $m)<option value="{{ $m->id }}" @selected(old('management_id', $school->management_id) == $m->id)>{{ $m->label }}</option>@endforeach</select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">School category</label>
                            <select name="category_id" class="form-select"><option value="">—</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $school->category_id) == $c->id)>{{ $c->label }}</option>@endforeach</select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" rows="2" class="form-control">{{ old('address', $school->address) }}</textarea>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label">Latitude</label>
                            <input name="latitude" value="{{ old('latitude', $school->latitude) }}" inputmode="decimal" class="form-control @error('latitude') is-invalid @enderror">
                            @error('latitude')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label">Longitude</label>
                            <input name="longitude" value="{{ old('longitude', $school->longitude) }}" inputmode="decimal" class="form-control @error('longitude') is-invalid @enderror">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select"><option value="active" @selected(old('status', $school->status) === 'active')>Active</option><option value="closed" @selected(old('status', $school->status) === 'closed')>Closed</option></select>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-white d-flex gap-2 justify-content-end">
                    <a href="{{ route('admin.schools.index') }}" class="btn btn-light">Cancel</a>
                    <button class="btn btn-primary"><i class="bi bi-check2"></i>Save school</button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
