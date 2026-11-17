<x-layouts.app title="My Profile">
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card card-elevated mb-4">
                <div class="card-body text-center p-4">
                    <form method="POST" action="{{ route('profile.photo') }}" enctype="multipart/form-data" class="profile-photo mb-3" data-photo-form
                          data-max-kb="{{ config('inspection.employee_photo.max_kb') }}">
                        @csrf
                        <div class="profile-photo-frame">
                            <x-user-avatar :user="$user" size="xl" data-photo-current />
                            <img alt="New photo preview" class="avatar avatar-xl avatar-img d-none" data-photo-preview>
                            <label class="profile-photo-btn" for="profile-photo-input" title="{{ $user->photo_path ? 'Change photo' : 'Upload photo' }}">
                                <i class="bi bi-camera-fill"></i><span class="visually-hidden">{{ $user->photo_path ? 'Change photo' : 'Upload photo' }}</span>
                            </label>
                            <input type="file" id="profile-photo-input" name="photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="d-none">
                        </div>
                        @unless ($user->photo_path)
                            <div class="text-xs text-warning-emphasis mt-2" data-photo-hint><i class="bi bi-exclamation-circle me-1"></i>No photo yet. Tap <i class="bi bi-camera-fill"></i> to add your passport-size photo.</div>
                        @endunless
                        <div class="text-danger text-xs mt-2 d-none" data-photo-error></div>
                        @error('photo')<div class="text-danger text-xs mt-2">{{ $message }}</div>@enderror
                        <div class="d-flex justify-content-center gap-2 mt-2 d-none" data-photo-actions>
                            <button type="button" class="btn btn-light btn-sm" data-photo-cancel><i class="bi bi-x-lg"></i>Cancel</button>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2"></i>Save photo</button>
                        </div>
                        <div class="text-secondary text-xs mt-1">JPG or PNG · max 1 MB</div>
                    </form>
                    <h2 class="h5 mb-0">{{ $user->name }}</h2>
                    <div class="text-secondary text-sm mb-2">{{ $user->designation?->label }}</div>
                    <x-status-badge :status="$user->status" />
                    <span class="badge-soft badge-soft-primary ms-1">{{ $user->level->label() }}</span>
                </div>
                <div class="card-body border-top">
                    <dl class="kv mb-0">
                        <dt>Employee ID</dt><dd>{{ $user->employee_id }}</dd>
                        <dt>Mobile</dt><dd>{{ $user->mobile }}</dd>
                        <dt>Email</dt><dd>{{ $user->email ?: '—' }}</dd>
                        <dt>Jurisdiction</dt><dd>{{ $user->jurisdictions->firstWhere('end_date', null)?->label() ?: '—' }}</dd>
                        <dt>Last login</dt><dd>{{ $user->last_login_at?->format('d M Y, h:i A') ?? '—' }}</dd>
                        @if ($user->directPermissions->isNotEmpty())
                            <dt>Extra rights</dt><dd>{{ $user->directPermissions->pluck('name')->implode(', ') }}</dd>
                        @endif
                    </dl>
                </div>
                <div class="card-footer bg-white d-grid gap-2">
                    <a href="{{ route('password.change') }}" class="btn btn-soft-primary"><i class="bi bi-key"></i>Change password</a>
                    <form method="POST" action="{{ route('logout') }}" class="d-grid d-lg-none">@csrf<button class="btn btn-soft-danger"><i class="bi bi-box-arrow-right"></i>Sign out</button></form>
                </div>
            </div>
            <div class="alert alert-light border text-sm"><i class="bi bi-lock me-1"></i>Your user level and jurisdiction can only be changed by the State Administrator.</div>
        </div>

        <div class="col-lg-7">
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-phone me-2 text-primary"></i>Change mobile number</div>
                <div class="card-body">
                    @if ($pendingMobile)
                        <div class="alert alert-warning border-0 mb-0">Request to change to <strong>{{ $pendingMobile->new_mobile }}</strong> is pending approval by the State Administrator.</div>
                    @else
                        <form method="POST" action="{{ route('profile.mobile') }}" class="row g-3">
                            @csrf
                            <input type="hidden" name="current" value="{{ $user->mobile }}">
                            <div class="col-sm-6">
                                <label class="form-label" for="new_mobile">New mobile number</label>
                                <input type="tel" inputmode="numeric" maxlength="10" name="new_mobile" id="new_mobile" value="{{ old('new_mobile') }}" class="form-control @error('new_mobile') is-invalid @enderror" required>
                                @error('new_mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="reason">Reason</label>
                                <input name="reason" id="reason" value="{{ old('reason') }}" class="form-control @error('reason') is-invalid @enderror" maxlength="255" required>
                                @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12"><button class="btn btn-primary"><i class="bi bi-send"></i>Send request</button></div>
                        </form>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-diagram-3 me-2 text-primary"></i>Jurisdiction history</div>
                <div class="list-card">
                    @foreach ($user->jurisdictions as $jur)
                        <div class="list-item">
                            <i class="bi bi-geo-alt text-secondary"></i>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">{{ $jur->label() }}</div>
                                <div class="text-xs text-secondary">From {{ $jur->start_date->format('d M Y') }} {{ $jur->end_date ? 'to '.$jur->end_date->format('d M Y') : '' }}</div>
                            </div>
                            @if (! $jur->end_date)<span class="badge-soft badge-soft-success">Current</span>@endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
