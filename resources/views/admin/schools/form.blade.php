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
                                <option value="">Select</option>@foreach ($districts as $d)<option value="{{ $d->id }}" @selected(old('district_id', $school->district_id) == $d->id)>{{ $d->label }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Block <span class="text-danger">*</span></label>
                            <select name="block_id" id="s_block" class="form-select @error('block_id') is-invalid @enderror" data-placeholder="Select" data-cascade-child="#s_cluster" data-cascade-url="{{ route('locations', 'clusters') }}" required>
                                <option value="">Select</option>@foreach ($blocks as $b)<option value="{{ $b->id }}" @selected(old('block_id', $school->block_id) == $b->id)>{{ $b->label }}</option>@endforeach
                            </select>
                            @error('block_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Cluster <span class="text-danger">*</span></label>
                            <select name="cluster_id" id="s_cluster" class="form-select @error('cluster_id') is-invalid @enderror" data-placeholder="Select" required>
                                <option value="">Select</option>@foreach ($clusters as $c)<option value="{{ $c->id }}" @selected(old('cluster_id', $school->cluster_id) == $c->id)>{{ $c->label }}</option>@endforeach
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
                        <div class="col-6 col-md-3">
                            <label class="form-label">School type</label>
                            <select name="school_type" class="form-select"><option value="">—</option>@foreach (\App\Models\School::SCHOOL_TYPES as $k => $l)<option value="{{ $k }}" @selected(old('school_type', $school->school_type) === $k)>{{ $l }}</option>@endforeach</select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label">Rural / Urban</label>
                            <select name="area_type" class="form-select"><option value="">—</option>@foreach (\App\Models\School::AREA_TYPES as $k => $l)<option value="{{ $k }}" @selected(old('area_type', $school->area_type) === $k)>{{ $l }}</option>@endforeach</select>
                        </div>
                        {{-- 23-11-2026: a school may teach in more than one medium (e.g. Assamese and Bodo); stored as "Assamese, Bodo" --}}
                        @php
                            $mediumsNow = $school->mediums();
                            $mediumsPicked = old('medium', array_values(array_intersect($mediumsNow, \App\Models\School::MEDIUMS)));
                            $mediumOther = old('medium_other', implode(', ', array_diff($mediumsNow, \App\Models\School::MEDIUMS)));
                        @endphp
                        <div class="col-12">
                            <label class="form-label d-block">Medium of instruction <small class="text-secondary fw-normal">· tick every medium taught</small></label>
                            <div class="d-flex flex-wrap gap-2 medium-picks">
                                @foreach (\App\Models\School::MEDIUMS as $m)
                                    <input type="checkbox" class="btn-check" name="medium[]" value="{{ $m }}" id="med_{{ $loop->index }}" @checked(in_array($m, (array) $mediumsPicked, true))>
                                    <label class="btn btn-sm btn-outline-primary" for="med_{{ $loop->index }}">{{ $m }}</label>
                                @endforeach
                            </div>
                            <input name="medium_other" value="{{ $mediumOther }}" maxlength="60" class="form-control mt-2 @error('medium_other') is-invalid @enderror" placeholder="Other medium, if not listed (e.g. Rabha)" aria-label="Other medium">
                            @error('medium_other')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @error('medium')<div class="text-danger text-xs mt-1">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="s_ac">Assembly constituency (AC)</label>
                            <select name="ac_id" id="s_ac" class="form-select @error('ac_id') is-invalid @enderror">
                                <option value="">—</option>
                                @foreach ($pcs as $pc)
                                    @if ($pc->acs->isNotEmpty())
                                        <optgroup label="{{ $pc->label }}">
                                            @foreach ($pc->acs as $ac)<option value="{{ $ac->id }}" @selected(old('ac_id', $school->ac_id) == $ac->id)>{{ $ac->label }}</option>@endforeach
                                        </optgroup>
                                    @endif
                                @endforeach
                            </select>
                            @error('ac_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Parliamentary constituency (PC)</label>
                            <input class="form-control" value="{{ $school->pc?->label ?? '' }}" placeholder="Follows the AC" readonly tabindex="-1">
                            <div class="form-text">Set from the chosen AC when you save.</div>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label">Address</label>
                            <textarea name="address" rows="2" class="form-control">{{ old('address', $school->address) }}</textarea>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Pincode</label>
                            <input name="pincode" value="{{ old('pincode', $school->pincode) }}" maxlength="6" inputmode="numeric" class="form-control font-monospace @error('pincode') is-invalid @enderror">
                            @error('pincode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        @php $streams = old('streams', is_array($school->streams) ? array_keys(array_filter($school->streams)) : []); @endphp
                        <div class="col-12">
                            <div class="form-check mb-2">
                                <input type="hidden" name="has_hs" value="0">
                                <input type="checkbox" name="has_hs" value="1" id="s_has_hs" class="form-check-input" @checked(old('has_hs', is_array($school->streams)))>
                                <label for="s_has_hs" class="form-check-label">Has Higher Secondary (streams offered)</label>
                            </div>
                            <div class="d-flex flex-wrap gap-3 ms-4">
                                @foreach (\App\Models\School::STREAMS as $k => $l)
                                    <div class="form-check"><input type="checkbox" name="streams[]" value="{{ $k }}" id="s_st_{{ $k }}" class="form-check-input" @checked(in_array($k, $streams, true))><label for="s_st_{{ $k }}" class="form-check-label">{{ $l }}</label></div>
                                @endforeach
                            </div>
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
