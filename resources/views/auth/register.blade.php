<x-layouts.guest title="Officer Registration" :wide="true">
    <div class="d-flex align-items-center justify-content-between mb-1">
        <h1 class="h3 fw-bold mb-0">Officer registration</h1>
        <a href="{{ route('login') }}" class="text-sm">Already registered?</a>
    </div>
    <p class="text-secondary mb-4">Your request will be verified and approved by a higher officer.</p>

    @if ($errors->any())
        <div class="alert alert-danger border-0"><i class="bi bi-exclamation-octagon me-1"></i>Please correct the highlighted fields.</div>
    @endif

    <form method="POST" action="{{ route('register') }}" enctype="multipart/form-data" id="register-form" novalidate>
        @csrf

        <div class="section-title">1 · Personal &amp; official details</div>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label" for="name">Full name <span class="text-danger">*</span></label>
                <input id="name" name="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" required maxlength="120" autocomplete="name">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="designation_id">Designation <span class="text-danger">*</span></label>
                <select id="designation_id" name="designation_id" class="form-select @error('designation_id') is-invalid @enderror" required>
                    <option value="">Select designation</option>
                    @foreach ($designations as $d)
                        <option value="{{ $d->id }}" @selected(old('designation_id') == $d->id)>{{ $d->label }}</option>
                    @endforeach
                </select>
                @error('designation_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="employee_id">Employee ID <span class="text-danger">*</span></label>
                <input id="employee_id" name="employee_id" value="{{ old('employee_id') }}" class="form-control @error('employee_id') is-invalid @enderror" required maxlength="40">
                @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="id_proof">Identity proof <span class="text-danger">*</span></label>
                <input type="file" id="id_proof" name="id_proof" accept=".pdf,.jpg,.jpeg,.png" class="form-control @error('id_proof') is-invalid @enderror" required>
                <div class="form-text">Posting order or departmental ID card · PDF, JPG or PNG · max 2 MB</div>
                @error('id_proof')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="section-title">2 · User level &amp; jurisdiction</div>
        <div class="mb-3">
            <div class="choice-group" role="radiogroup" aria-label="User level">
                @foreach ($levels as $l)
                    <input type="radio" name="level" id="level_{{ $l->value }}" value="{{ $l->value }}" @checked(old('level') === $l->value)
                           data-needs-district="{{ $l->needsDistrict() ? 1 : 0 }}" data-needs-block="{{ $l->needsBlock() ? 1 : 0 }}" data-needs-cluster="{{ $l->needsCluster() ? 1 : 0 }}">
                    <label for="level_{{ $l->value }}"><i class="bi bi-{{ ['state' => 'globe', 'district' => 'buildings', 'block' => 'grid-3x3-gap', 'cluster' => 'pin-map'][$l->value] }}"></i>{{ $l->label() }}</label>
                @endforeach
            </div>
            @error('level')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="row g-3 mb-4">
            <div class="col-md-6 col-lg-3">
                <label class="form-label" for="state_id">State <span class="text-danger">*</span></label>
                <select id="state_id" name="state_id" class="form-select @error('state_id') is-invalid @enderror" required
                        data-cascade-child="#district_id" data-cascade-url="{{ route('locations', 'districts') }}">
                    <option value="">Select state</option>
                    @foreach ($states as $s)
                        <option value="{{ $s->id }}" @selected(old('state_id', $states->count() === 1 ? $s->id : null) == $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
                @error('state_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 col-lg-3 loc-field" data-loc="district">
                <label class="form-label" for="district_id">District <span class="text-danger">*</span></label>
                <select id="district_id" name="district_id" class="form-select @error('district_id') is-invalid @enderror" data-placeholder="Select district" data-old="{{ old('district_id') }}"
                        data-cascade-child="#block_id" data-cascade-url="{{ route('locations', 'blocks') }}" disabled><option value="">Select district</option></select>
                @error('district_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 col-lg-3 loc-field" data-loc="block">
                <label class="form-label" for="block_id">Block <span class="text-danger">*</span></label>
                <select id="block_id" name="block_id" class="form-select @error('block_id') is-invalid @enderror" data-placeholder="Select block" data-old="{{ old('block_id') }}"
                        data-cascade-child="#cluster_id" data-cascade-url="{{ route('locations', 'clusters') }}" disabled><option value="">Select block</option></select>
                @error('block_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 col-lg-3 loc-field" data-loc="cluster">
                <label class="form-label" for="cluster_id">Cluster <span class="text-danger">*</span></label>
                <select id="cluster_id" name="cluster_id" class="form-select @error('cluster_id') is-invalid @enderror" data-placeholder="Select cluster" data-old="{{ old('cluster_id') }}" disabled><option value="">Select cluster</option></select>
                @error('cluster_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="section-title">3 · Contact &amp; sign-in</div>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label" for="mobile">Mobile number <span class="text-danger">*</span></label>
                <input type="tel" inputmode="numeric" maxlength="10" id="mobile" name="mobile" value="{{ old('mobile') }}" class="form-control @error('mobile') is-invalid @enderror" required autocomplete="tel">
                <div class="form-text">This will be your login ID.</div>
                @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="email">Email address <span class="text-secondary text-xs">(optional)</span></label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" autocomplete="email">
                <div class="form-text">Needed for self-service password reset.</div>
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="password">Password <span class="text-danger">*</span></label>
                <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" required autocomplete="new-password">
                <div class="progress-thin progress mt-2" style="height:5px"><div class="progress-bar" id="pw-meter" style="width:0"></div></div>
                <div class="form-text" id="pw-hint">At least 8 characters with upper case, lower case, digit and special character.</div>
                @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="password_confirmation">Confirm password <span class="text-danger">*</span></label>
                <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" required autocomplete="new-password">
            </div>
        </div>

        @if (config('inspection.captcha_enabled'))
            <div class="row"><div class="col-md-6">@include('partials.captcha')</div></div>
        @endif

        <div class="form-check mb-4 p-3 rounded-3 bg-light ps-5">
            <input class="form-check-input" type="checkbox" name="privacy" id="privacy" value="1" @checked(old('privacy')) required>
            <label class="form-check-label text-sm" for="privacy">
                <strong>Privacy notice.</strong> Your details and identity proof are used only to verify and manage your account.
                Your device location is collected only while you conduct an inspection. Photographs are stored securely and shown only to authorised officers.
            </label>
            @error('privacy')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <button class="btn btn-primary btn-lg w-100" type="submit"><i class="bi bi-send"></i>Submit registration</button>
    </form>

    @push('scripts') @vite('resources/js/register.js') @endpush
</x-layouts.guest>
