<x-layouts.portal title="Officer Registration">
    <x-slot:hero>
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="reg-badge"><i class="bi bi-person-badge"></i>Inspecting Officer Registration</span>
                <h1 class="reg-hero-title">Register to inspect schools</h1>
                <p class="reg-hero-sub mb-0">For State, District, Block and Cluster level officers. Your request is verified by a higher officer before you can sign in.</p>
            </div>
            <div class="col-lg-4">
                <ol class="reg-steps">
                    <li><span>1</span>Personal &amp; official details</li>
                    <li><span>2</span>Level &amp; jurisdiction</li>
                    <li><span>3</span>Sign-in details</li>
                </ol>
            </div>
        </div>
    </x-slot:hero>

    <div class="reg-card">
        @if ($errors->any())
            <div class="alert alert-danger border-0 d-flex gap-2 align-items-center mb-4"><i class="bi bi-exclamation-octagon"></i>Please correct the highlighted fields below.</div>
        @endif

        <form method="POST" action="{{ route('register') }}" enctype="multipart/form-data" id="register-form" novalidate>
            @csrf

            {{-- ===== 1. Personal & official ===== --}}
            <div class="reg-block">
                <div class="reg-block-head">
                    <span class="reg-icon tone-primary"><i class="bi bi-person-vcard"></i></span>
                    <div><h2>Personal &amp; official details</h2><p>As recorded in your posting order or departmental ID card.</p></div>
                </div>
                <div class="reg-block-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="name">Full name <span class="req">*</span></label>
                            <input id="name" name="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" required maxlength="120" autocomplete="name" placeholder="e.g. Ananya Sharma">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="designation_id">Designation <span class="req">*</span></label>
                            <select id="designation_id" name="designation_id" class="form-select @error('designation_id') is-invalid @enderror" required>
                                <option value="">Select designation</option>
                                @foreach ($designations as $d)<option value="{{ $d->id }}" @selected(old('designation_id') == $d->id)>{{ $d->label }}</option>@endforeach
                            </select>
                            @error('designation_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="employee_id">Employee ID <span class="req">*</span></label>
                            <input id="employee_id" name="employee_id" value="{{ old('employee_id') }}" class="form-control @error('employee_id') is-invalid @enderror" required maxlength="40" placeholder="Departmental employee code">
                            @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 upload-col">
                            <label class="form-label" for="photo">Employee photo <span class="req">*</span></label>
                            <div class="upload-zone upload-zone-photo @error('photo') is-invalid @enderror" data-exts="jpg,jpeg,png" data-max-kb="{{ config('inspection.employee_photo.max_kb') }}">
                                <input type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required aria-describedby="photo-hint">
                                <div class="upload-empty">
                                    <span class="upload-icon upload-icon-round"><i class="bi bi-person-bounding-box"></i></span>
                                    <div class="upload-text">
                                        <div class="t">Passport-size photograph</div>
                                        <div class="s"><span class="text-primary fw-semibold">Click to choose</span> or drag &amp; drop · clear face</div>
                                        <div class="upload-chips" id="photo-hint">
                                            <span><i class="bi bi-file-earmark-image"></i>JPG / PNG</span>
                                            <span><i class="bi bi-hdd"></i>Max 1 MB</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="upload-done d-none">
                                    <span class="upload-thumb upload-thumb-round"><img alt="Preview of employee photo" class="d-none"><i class="bi bi-file-earmark-pdf-fill d-none"></i></span>
                                    <div class="min-w-0 flex-grow-1">
                                        <div class="upload-name text-truncate"></div>
                                        <div class="upload-meta"><i class="bi bi-check-circle-fill"></i><span></span></div>
                                    </div>
                                    <button type="button" class="btn btn-light btn-sm upload-remove" aria-label="Remove photo"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </div>
                            <div class="upload-error text-danger text-xs mt-1 d-none"></div>
                            @error('photo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 upload-col">
                            <label class="form-label" for="id_proof">Identity proof <span class="req">*</span></label>
                            <div class="upload-zone @error('id_proof') is-invalid @enderror" data-exts="pdf,jpg,jpeg,png" data-max-kb="{{ config('inspection.id_proof.max_kb') }}">
                                <input type="file" id="id_proof" name="id_proof" accept=".pdf,.jpg,.jpeg,.png" required aria-describedby="id-proof-hint">
                                {{-- Empty state --}}
                                <div class="upload-empty">
                                    <span class="upload-icon"><i class="bi bi-person-badge"></i></span>
                                    <div class="upload-text">
                                        <div class="t">Department ID card / posting order</div>
                                        <div class="s"><span class="text-primary fw-semibold">Click to choose</span> or drag &amp; drop · clear copy</div>
                                        <div class="upload-chips" id="id-proof-hint">
                                            <span><i class="bi bi-file-earmark-pdf"></i>PDF</span>
                                            <span><i class="bi bi-file-earmark-image"></i>JPG / PNG</span>
                                            <span><i class="bi bi-hdd"></i>Max 2 MB</span>
                                        </div>
                                    </div>
                                </div>
                                {{-- Selected state --}}
                                <div class="upload-done d-none">
                                    <span class="upload-thumb"><img alt="Preview of identity proof" class="d-none"><i class="bi bi-file-earmark-pdf-fill d-none"></i></span>
                                    <div class="min-w-0 flex-grow-1">
                                        <div class="upload-name text-truncate"></div>
                                        <div class="upload-meta"><i class="bi bi-check-circle-fill"></i><span></span></div>
                                    </div>
                                    <button type="button" class="btn btn-light btn-sm upload-remove" aria-label="Remove file"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </div>
                            <div class="upload-error text-danger text-xs mt-1 d-none"></div>
                            @error('id_proof')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </div>

            {{-- ===== 2. Level & jurisdiction ===== --}}
            <div class="reg-block">
                <div class="reg-block-head">
                    <span class="reg-icon tone-success"><i class="bi bi-diagram-3"></i></span>
                    <div><h2>Level &amp; jurisdiction</h2><p>Choose your user level; only the areas under your posting are asked. This cannot be changed later by you.</p></div>
                </div>
                <div class="reg-block-body">
                    <label class="form-label">User level <span class="req">*</span></label>
                    <div class="level-pills" role="radiogroup" aria-label="User level">
                        @foreach ($levels as $l)
                            <input type="radio" name="level" id="level_{{ $l->value }}" value="{{ $l->value }}" @checked(old('level') === $l->value)
                                   data-needs-district="{{ $l->needsDistrict() ? 1 : 0 }}" data-needs-block="{{ $l->needsBlock() ? 1 : 0 }}" data-needs-cluster="{{ $l->needsCluster() ? 1 : 0 }}">
                            <label for="level_{{ $l->value }}">
                                <i class="bi bi-{{ ['state' => 'globe2', 'district' => 'buildings', 'block' => 'grid-3x3-gap', 'cluster' => 'pin-map'][$l->value] }}"></i>
                                <span>{{ str_replace(' Level', '', $l->label()) }}</span>
                                <small>{{ ['state' => 'Directorate', 'district' => 'DEEO / IS', 'block' => 'BEEO / BRP', 'cluster' => 'CRCC'][$l->value] }}</small>
                            </label>
                        @endforeach
                    </div>
                    @error('level')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label" for="state_id">State <span class="req">*</span></label>
                            <select id="state_id" name="state_id" class="form-select @error('state_id') is-invalid @enderror" required
                                    data-cascade-child="#district_id" data-cascade-url="{{ route('locations', 'districts') }}">
                                <option value="">Select state</option>
                                @foreach ($states as $s)<option value="{{ $s->id }}" @selected(old('state_id', $states->count() === 1 ? $s->id : null) == $s->id)>{{ $s->name }}</option>@endforeach
                            </select>
                            @error('state_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 loc-field" data-loc="district">
                            <label class="form-label" for="district_id">District <span class="req">*</span></label>
                            <select id="district_id" name="district_id" class="form-select @error('district_id') is-invalid @enderror" data-placeholder="Select district" data-old="{{ old('district_id') }}"
                                    data-cascade-child="#block_id" data-cascade-url="{{ route('locations', 'blocks') }}" disabled><option value="">Select district</option></select>
                            @error('district_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 loc-field" data-loc="block">
                            <label class="form-label" for="block_id">Block <span class="req">*</span></label>
                            <select id="block_id" name="block_id" class="form-select @error('block_id') is-invalid @enderror" data-placeholder="Select block" data-old="{{ old('block_id') }}"
                                    data-cascade-child="#cluster_id" data-cascade-url="{{ route('locations', 'clusters') }}" disabled><option value="">Select block</option></select>
                            @error('block_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 loc-field" data-loc="cluster">
                            <label class="form-label" for="cluster_id">Cluster <span class="req">*</span></label>
                            <select id="cluster_id" name="cluster_id" class="form-select @error('cluster_id') is-invalid @enderror" data-placeholder="Select cluster" data-old="{{ old('cluster_id') }}" disabled><option value="">Select cluster</option></select>
                            @error('cluster_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </div>

            {{-- ===== 3. Sign-in ===== --}}
            <div class="reg-block">
                <div class="reg-block-head">
                    <span class="reg-icon tone-warning"><i class="bi bi-shield-lock"></i></span>
                    <div><h2>Sign-in details</h2><p>Your mobile number is your login ID. Keep your password private.</p></div>
                </div>
                <div class="reg-block-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="mobile">Mobile number <span class="req">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">+91</span>
                                <input type="tel" inputmode="numeric" maxlength="10" id="mobile" name="mobile" value="{{ old('mobile') }}" class="form-control @error('mobile') is-invalid @enderror" required autocomplete="tel" placeholder="10-digit number">
                                @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">Email address <span class="opt">(optional)</span></label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" autocomplete="email" placeholder="name@example.com">
                            <div class="form-text">Used only for self-service password reset.</div>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password">Password <span class="req">*</span></label>
                            <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" required autocomplete="new-password">
                            <div class="progress-thin progress mt-2" style="height:4px"><div class="progress-bar" id="pw-meter" style="width:0"></div></div>
                            <div class="form-text">At least 8 characters with A–Z, a–z, 0–9 and a symbol.</div>
                            @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password_confirmation">Confirm password <span class="req">*</span></label>
                            <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" required autocomplete="new-password">
                        </div>
                        @if (config('inspection.captcha_enabled'))
                            <div class="col-md-8 col-lg-6">@include('partials.captcha')</div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ===== Submit ===== --}}
            <div class="reg-submit">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" name="privacy" id="privacy" value="1" @checked(old('privacy')) required>
                    <label class="form-check-label text-sm" for="privacy">I accept the <a href="#privacy-text" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="privacy-text">privacy notice</a> and confirm the details are correct.</label>
                    @error('privacy')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    <div class="collapse text-xs text-secondary mt-2" id="privacy-text">Your details and identity proof are used only to verify and manage your account. Your device location is collected only while you conduct an inspection. Photographs are stored securely and shown only to authorised officers.</div>
                </div>
                <div class="d-flex flex-column flex-sm-row gap-2 ms-md-auto">
                    <a href="{{ route('login') }}" class="btn btn-light order-2 order-sm-1">Already registered? Sign in</a>
                    <button class="btn btn-primary px-4 order-1 order-sm-2" type="submit"><i class="bi bi-send"></i>Submit registration</button>
                </div>
            </div>
        </form>
    </div>

    @push('scripts') @vite('resources/js/register.js') @endpush
</x-layouts.portal>
