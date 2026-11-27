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
        @if ($errors->has('area_taken'))
            {{-- Modification V9: one active officer per cluster --}}
            <div class="alert alert-warning border-0 d-flex gap-2 align-items-start mb-4" role="alert" data-area-taken>
                <i class="bi bi-person-lock fs-5"></i>
                <div><div class="fw-semibold mb-1">Officer already registered</div>{{ $errors->first('area_taken') }}</div>
            </div>
        @elseif ($errors->any())
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
                        <div class="col-md-6">
                            <label class="form-label" for="name">Full name <span class="req">*</span></label>
                            <input id="name" name="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" required minlength="3" maxlength="120" pattern="[A-Za-zÀ-ɏ][A-Za-zÀ-ɏ .'\-]*" title="Letters, spaces, dots, apostrophes and hyphens" autocomplete="name" placeholder="e.g. Ananya Sharma">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="gender">Gender <span class="req">*</span></label>
                            <select id="gender" name="gender" class="form-select @error('gender') is-invalid @enderror" required>
                                <option value="">Select</option>
                                @foreach (\App\Models\User::GENDERS as $k => $l)<option value="{{ $k }}" @selected(old('gender') === $k)>{{ $l }}</option>@endforeach
                            </select>
                            @error('gender')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        {{-- 27-11-2026: date of birth (18 to 70 years old, User::dobRules()) --}}
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="date_of_birth">Date of birth <span class="req">*</span></label>
                            <input type="date" id="date_of_birth" name="date_of_birth" value="{{ old('date_of_birth') }}" required autocomplete="bday" placeholder="dd-mm-yyyy" data-date-typing
                                   min="{{ now()->subYears(\App\Models\User::MAX_AGE)->toDateString() }}" max="{{ now()->subYears(\App\Models\User::MIN_AGE)->toDateString() }}"
                                   class="form-control @error('date_of_birth') is-invalid @enderror">
                            @error('date_of_birth')<div class="invalid-feedback">{{ $message }}</div>@enderror<div class="form-text" data-date-hint hidden>Type it (e.g. 15061985) or pick from the calendar.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="designation_id">Designation <span class="req">*</span></label>
                            <select id="designation_id" name="designation_id" class="form-select @error('designation_id') is-invalid @enderror" required data-other-value="{{ $otherDesignationId }}" data-other-target="#designation-other-wrap">
                                <option value="">Select designation</option>
                                @foreach ($designations as $d)<option value="{{ $d->id }}" @selected(old('designation_id') == $d->id)>{{ $d->label }}</option>@endforeach
                            </select>
                            @error('designation_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            {{-- Modification V7: "Other" designation is typed in --}}
                            <div id="designation-other-wrap" class="designation-other mt-2 {{ old('designation_id') && old('designation_id') == $otherDesignationId ? '' : 'd-none' }}">
                                <label class="visually-hidden" for="designation_other">Your designation</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-pencil-square"></i></span>
                                    <input id="designation_other" name="designation_other" value="{{ old('designation_other') }}" maxlength="120" placeholder="Type your designation"
                                           class="form-control @error('designation_other') is-invalid @enderror" autocomplete="organization-title">
                                    @error('designation_other')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="employee_id">Employee ID <span class="opt">(optional)</span></label>
                            <input id="employee_id" name="employee_id" value="{{ old('employee_id') }}" class="form-control @error('employee_id') is-invalid @enderror" maxlength="40" pattern="[A-Za-z0-9/._\-]+" placeholder="Departmental employee code">
                            @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 upload-col">
                            <label class="form-label" for="photo">Employee photo <span class="req">*</span></label>
                            <div class="upload-zone upload-zone-photo @error('photo') is-invalid @enderror" data-exts="jpg,jpeg,png" data-max-kb="{{ config('inspection.employee_photo.max_kb') }}" data-max-side="{{ config('inspection.employee_photo.max_side_px') }}" data-short-side="{{ config('inspection.employee_photo.short_side_px') }}" @if ($kept['photo']) data-kept-url="{{ route('register.kept', 'photo') }}" data-kept-name="{{ $kept['photo']['name'] }}" data-kept-size="{{ $kept['photo']['size'] }}" data-kept-pdf="{{ str_ends_with(strtolower($kept['photo']['path']), '.pdf') ? 1 : 0 }}"@endif>
                                @if ($kept['photo'])<input type="hidden" name="keep_photo" value="1" class="keep-flag">@endif
                                <input type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png" @required(! $kept['photo']) aria-describedby="photo-hint">
                                <div class="upload-empty">
                                    <span class="upload-icon upload-icon-round"><i class="bi bi-person-bounding-box"></i></span>
                                    <div class="upload-text">
                                        <div class="t">Passport-size photograph</div>
                                        <div class="s"><span class="text-primary fw-semibold">Click to choose</span> or drag &amp; drop · clear face</div>
                                        <div class="upload-chips" id="photo-hint">
                                            <span><i class="bi bi-file-earmark-image"></i>{{ \App\Support\FileSize::types(config('inspection.employee_photo.mimes')) }}</span>
                                            <span><i class="bi bi-hdd"></i>Max {{ \App\Support\FileSize::human(config('inspection.employee_photo.max_kb')) }} </span>
                                        </div>
                                        {{-- 27-11-2026: or take it with the front camera (selfie.js); it goes into the same photo input --}}
                                        <button type="button" class="btn btn-soft-primary btn-sm selfie-open" data-selfie-open data-no-busy><i class="bi bi-camera-fill"></i>Take a selfie</button>
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
                            <input type="file" accept="image/jpeg,image/png" capture="user" class="d-none" data-selfie-fallback tabindex="-1" aria-hidden="true">
                        </div>
                        <div class="col-md-6 upload-col">
                            <label class="form-label" for="id_proof">Identity proof <span class="opt">(optional)</span></label>
                            <div class="upload-zone @error('id_proof') is-invalid @enderror" data-exts="pdf,jpg,jpeg,png" data-max-kb="{{ config('inspection.id_proof.max_kb') }}" data-max-side="{{ config('inspection.id_proof.max_side_px') }}" data-short-side="{{ config('inspection.id_proof.short_side_px') }}" @if ($kept['id_proof']) data-kept-url="{{ route('register.kept', 'id_proof') }}" data-kept-name="{{ $kept['id_proof']['name'] }}" data-kept-size="{{ $kept['id_proof']['size'] }}" data-kept-pdf="{{ str_ends_with(strtolower($kept['id_proof']['path']), '.pdf') ? 1 : 0 }}"@endif>
                                @if ($kept['id_proof'])<input type="hidden" name="keep_id_proof" value="1" class="keep-flag">@endif
                                <input type="file" id="id_proof" name="id_proof" accept=".pdf,.jpg,.jpeg,.png" aria-describedby="id-proof-hint">
                                {{-- Empty state --}}
                                <div class="upload-empty">
                                    <span class="upload-icon"><i class="bi bi-person-badge"></i></span>
                                    <div class="upload-text">
                                        <div class="t">Department ID card / posting order</div>
                                        <div class="s"><span class="text-primary fw-semibold">Click to choose</span> or drag &amp; drop · clear copy</div>
                                        <div class="upload-chips" id="id-proof-hint">
                                            <span><i class="bi bi-file-earmark-pdf"></i>PDF</span>
                                            <span><i class="bi bi-file-earmark-image"></i>JPG / PNG</span>
                                            <span><i class="bi bi-hdd"></i>Max {{ \App\Support\FileSize::human(config('inspection.id_proof.max_kb')) }}</span>
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
                                <i class="bi {{ $l->icon() }}" aria-hidden="true"></i>
                                <span>{{ str_replace(' Level', '', $l->label()) }}</span>
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
                                <input type="tel" inputmode="numeric" maxlength="10" pattern="[6-9][0-9]{9}" data-mobile id="mobile" name="mobile" value="{{ old('mobile') }}" class="form-control @error('mobile') is-invalid @enderror" required autocomplete="tel" placeholder="10-digit number">
                                @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">Email address <span class="opt">(optional)</span></label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" maxlength="150" autocomplete="email" placeholder="name@example.com">
                            <div class="form-text">Used only for self-service password reset.</div>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password">Password <span class="req">*</span></label>
                            <div class="input-group">
                                <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" required minlength="8" maxlength="64" autocomplete="new-password">
                                @include('partials.pw-toggle')
                            </div>
                            <div class="progress-thin progress mt-2" style="height:4px"><div class="progress-bar" id="pw-meter" style="width:0"></div></div>
                            {{-- 26-11-2026: live checklist of the password rules (register.js) --}}
                            <ul class="pw-rules" id="pw-rules" aria-live="polite" aria-label="Password rules">
                                @foreach (['len' => '8 to 64 characters', 'upper' => 'A–Z', 'lower' => 'a–z', 'digit' => '0–9', 'symbol' => 'A symbol (e.g. @ # $)'] as $k => $t)
                                    <li data-rule="{{ $k }}"><i class="bi bi-circle"></i>{{ $t }}</li>
                                @endforeach
                            </ul>
                            @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password_confirmation">Confirm password <span class="req">*</span></label>
                            <div class="input-group">
                                <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" required maxlength="64" autocomplete="new-password">
                                @include('partials.pw-toggle')
                            </div>
                            <div class="pw-match d-none" id="pw-match" aria-live="polite"></div>
                        </div>
                        @if (config('inspection.captcha_enabled'))
                            <div class="col-12">@include('partials.captcha', ['inline' => true])</div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ===== Submit ===== --}}
            <div class="reg-submit">
                {{-- Modification V7: the consent is a highlighted panel so it is not missed --}}
                <div class="reg-consent @error('privacy') is-invalid @enderror" id="reg-consent">
                    <span class="reg-consent-icon" aria-hidden="true"><i class="bi bi-shield-check"></i></span>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="privacy" id="privacy" value="1" @checked(old('privacy')) required aria-describedby="privacy-hint">
                        <label class="form-check-label" for="privacy">I accept the <a href="#privacy-text" data-bs-toggle="collapse" role="button" aria-expanded="false" aria-controls="privacy-text">privacy notice</a> and confirm the details are correct. <span class="req">*</span></label>
                        <div class="reg-consent-hint" id="privacy-hint">Tick this box to submit your registration.</div>
                        @error('privacy')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        <div class="collapse text-xs text-secondary mt-2" id="privacy-text">Your details and identity proof are used only to verify and manage your account. Your device location is collected only while you conduct an inspection. Photographs are stored securely and shown only to authorised officers.</div>
                    </div>
                </div>
                <div class="d-flex flex-column flex-sm-row gap-2 ms-md-auto">
                    <a href="{{ route('login') }}" class="btn btn-light order-2 order-sm-1">Already registered? Sign in</a>
                    <button class="btn btn-primary px-4 order-1 order-sm-2" type="submit" data-loading-text="Submitting registration…"><i class="bi bi-send"></i>Submit registration</button>
                </div>
            </div>
        </form>
    </div>

    {{-- Selfie camera (27-11-2026, resources/js/selfie.js) --}}
    <div class="modal fade" id="selfie-modal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="selfie-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-6" id="selfie-title"><i class="bi bi-camera-fill me-1 text-primary"></i>Take a selfie</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="selfie-stage">
                        <video class="selfie-video" playsinline muted autoplay data-selfie-video></video>
                        <canvas class="selfie-shot d-none" data-selfie-shot></canvas>
                        <span class="selfie-guide" aria-hidden="true"></span>
                        <div class="selfie-wait" data-selfie-wait><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Starting the camera…</div>
                    </div>
                    <div class="alert alert-warning d-none mt-3 mb-0" role="alert" data-selfie-error></div>
                    <p class="text-secondary text-xs mt-2 mb-0" data-selfie-tip>Face the camera in good light and keep your face inside the circle.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-light d-none" data-selfie-retake><i class="bi bi-arrow-repeat"></i>Retake</button>
                    <button type="button" class="btn btn-primary" data-selfie-capture disabled><i class="bi bi-camera-fill"></i>Capture</button>
                    <button type="button" class="btn btn-success d-none" data-selfie-use><i class="bi bi-check-lg"></i>Use photo</button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts') @vite('resources/js/register.js') @endpush
</x-layouts.portal>
