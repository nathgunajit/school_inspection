<x-layouts.guest title="Officer Registration" :wide="true">
    <div class="d-flex align-items-start justify-content-between gap-2">
        <div>
            <div class="auth-title">Officer registration</div>
            <div class="auth-sub">Your request is verified and approved by a higher officer.</div>
        </div>
        <a href="{{ route('login') }}" class="btn btn-sm btn-light text-nowrap"><i class="bi bi-box-arrow-in-right"></i>Sign in</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger border-0 py-2 text-sm"><i class="bi bi-exclamation-octagon me-1"></i>Please correct the highlighted fields.</div>
    @endif

    <form method="POST" action="{{ route('register') }}" enctype="multipart/form-data" id="register-form" novalidate>
        @csrf

        {{-- 1. Personal --}}
        <div class="reg-section">
            <div class="reg-head"><span class="num">1</span>Personal details</div>
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label text-sm" for="name">Full name</label>
                    <input id="name" name="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" required maxlength="120" autocomplete="name">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label text-sm" for="designation_id">Designation</label>
                    <select id="designation_id" name="designation_id" class="form-select @error('designation_id') is-invalid @enderror" required>
                        <option value="">Select</option>
                        @foreach ($designations as $d)<option value="{{ $d->id }}" @selected(old('designation_id') == $d->id)>{{ $d->label }}</option>@endforeach
                    </select>
                    @error('designation_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label text-sm" for="employee_id">Employee ID</label>
                    <input id="employee_id" name="employee_id" value="{{ old('employee_id') }}" class="form-control @error('employee_id') is-invalid @enderror" required maxlength="40">
                    @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label text-sm" for="id_proof">Identity proof <span class="text-secondary text-xs">(PDF/JPG/PNG, ≤ 2 MB)</span></label>
                    <input type="file" id="id_proof" name="id_proof" accept=".pdf,.jpg,.jpeg,.png" class="form-control @error('id_proof') is-invalid @enderror" required>
                    @error('id_proof')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        {{-- 2. Jurisdiction --}}
        <div class="reg-section">
            <div class="reg-head"><span class="num">2</span>User level &amp; jurisdiction</div>
            <div class="level-pills mb-2" role="radiogroup" aria-label="User level">
                @foreach ($levels as $l)
                    <input type="radio" name="level" id="level_{{ $l->value }}" value="{{ $l->value }}" @checked(old('level') === $l->value)
                           data-needs-district="{{ $l->needsDistrict() ? 1 : 0 }}" data-needs-block="{{ $l->needsBlock() ? 1 : 0 }}" data-needs-cluster="{{ $l->needsCluster() ? 1 : 0 }}">
                    <label for="level_{{ $l->value }}"><i class="bi bi-{{ ['state' => 'globe2', 'district' => 'buildings', 'block' => 'grid-3x3-gap', 'cluster' => 'pin-map'][$l->value] }}"></i>{{ str_replace(' Level', '', $l->label()) }}</label>
                @endforeach
            </div>
            @error('level')<div class="invalid-feedback d-block mb-2">{{ $message }}</div>@enderror
            <div class="row g-2">
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label text-sm" for="state_id">State</label>
                    <select id="state_id" name="state_id" class="form-select @error('state_id') is-invalid @enderror" required
                            data-cascade-child="#district_id" data-cascade-url="{{ route('locations', 'districts') }}">
                        <option value="">Select</option>
                        @foreach ($states as $s)<option value="{{ $s->id }}" @selected(old('state_id', $states->count() === 1 ? $s->id : null) == $s->id)>{{ $s->name }}</option>@endforeach
                    </select>
                    @error('state_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-6 col-lg-3 loc-field" data-loc="district">
                    <label class="form-label text-sm" for="district_id">District</label>
                    <select id="district_id" name="district_id" class="form-select @error('district_id') is-invalid @enderror" data-placeholder="Select" data-old="{{ old('district_id') }}"
                            data-cascade-child="#block_id" data-cascade-url="{{ route('locations', 'blocks') }}" disabled><option value="">Select</option></select>
                    @error('district_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-6 col-lg-3 loc-field" data-loc="block">
                    <label class="form-label text-sm" for="block_id">Block</label>
                    <select id="block_id" name="block_id" class="form-select @error('block_id') is-invalid @enderror" data-placeholder="Select" data-old="{{ old('block_id') }}"
                            data-cascade-child="#cluster_id" data-cascade-url="{{ route('locations', 'clusters') }}" disabled><option value="">Select</option></select>
                    @error('block_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-6 col-lg-3 loc-field" data-loc="cluster">
                    <label class="form-label text-sm" for="cluster_id">Cluster</label>
                    <select id="cluster_id" name="cluster_id" class="form-select @error('cluster_id') is-invalid @enderror" data-placeholder="Select" data-old="{{ old('cluster_id') }}" disabled><option value="">Select</option></select>
                    @error('cluster_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        {{-- 3. Account --}}
        <div class="reg-section">
            <div class="reg-head"><span class="num">3</span>Sign-in details</div>
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label text-sm" for="mobile">Mobile number <span class="text-secondary text-xs">(login ID)</span></label>
                    <input type="tel" inputmode="numeric" maxlength="10" id="mobile" name="mobile" value="{{ old('mobile') }}" class="form-control @error('mobile') is-invalid @enderror" required autocomplete="tel">
                    @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label text-sm" for="email">Email <span class="text-secondary text-xs">(optional, for password reset)</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" autocomplete="email">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label text-sm" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" required autocomplete="new-password">
                    <div class="progress-thin progress mt-1" style="height:4px"><div class="progress-bar" id="pw-meter" style="width:0"></div></div>
                    <div class="form-text text-xs">8+ characters with A–Z, a–z, 0–9 and a symbol.</div>
                    @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label text-sm" for="password_confirmation">Confirm password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" required autocomplete="new-password">
                </div>
                @if (config('inspection.captcha_enabled'))
                    <div class="col-md-6">@include('partials.captcha')</div>
                @endif
            </div>
        </div>

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="privacy" id="privacy" value="1" @checked(old('privacy')) required>
            <label class="form-check-label text-sm" for="privacy">I accept the <a href="#privacy-text" data-bs-toggle="collapse" role="button" aria-expanded="false">privacy notice</a>.</label>
            <div class="collapse text-xs text-secondary mt-1" id="privacy-text">Your details and identity proof are used only to verify and manage your account. Your device location is collected only while you conduct an inspection. Photographs are stored securely and shown only to authorised officers.</div>
            @error('privacy')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>

        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-send"></i>Submit registration</button>
    </form>

    @push('scripts') @vite('resources/js/register.js') @endpush
</x-layouts.guest>
