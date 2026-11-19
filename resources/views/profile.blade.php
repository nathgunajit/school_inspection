@php
    $jur = $user->jurisdictions->firstWhere('end_date', null);
    $canInspect = $user->can('conduct_inspections');
@endphp
<x-layouts.app title="My Profile">
    {{-- ============ Hero: photo, name, role (Modification V3 item 1) ============ --}}
    <section class="pf-hero">
        <div class="pf-hero-band" aria-hidden="true"></div>
        <div class="pf-hero-body">
            <form method="POST" action="{{ route('profile.photo') }}" enctype="multipart/form-data" class="profile-photo pf-photo" data-photo-form
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
                    <div class="pf-photo-hint" data-photo-hint><i class="bi bi-exclamation-circle me-1"></i>No photo yet. Tap <i class="bi bi-camera-fill"></i> to add your passport-size photo.</div>
                @endunless
                <div class="text-danger text-xs mt-2 d-none" data-photo-error></div>
                @error('photo')<div class="text-danger text-xs mt-2">{{ $message }}</div>@enderror
                <div class="d-flex justify-content-center gap-2 mt-2 d-none" data-photo-actions>
                    <button type="button" class="btn btn-light btn-sm" data-photo-cancel><i class="bi bi-x-lg"></i>Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2"></i>Save photo</button>
                </div>
            </form>

            <div class="pf-identity">
                <h2 class="pf-name">{{ $user->name }}</h2>
                <div class="pf-role">{{ $user->designation?->label ?? 'Officer' }}</div>
                <div class="pf-chips">
                    <span class="pf-chip level"><i class="bi bi-diagram-3"></i>{{ $user->level->label() }} level</span>
                    <x-status-badge :status="$user->status" />
                    @if ($jur)<span class="pf-chip"><i class="bi bi-geo-alt"></i>{{ $jur->label() }}</span>@endif
                </div>
                <div class="pf-since">
                    <span><i class="bi bi-calendar-check"></i>Member since {{ $user->created_at?->format('M Y') ?? '—' }}</span>
                    <span><i class="bi bi-clock-history"></i>Last sign-in {{ $user->last_login_at?->format('d M Y, h:i A') ?? '—' }}</span>
                </div>
            </div>

            <div class="pf-actions">
                <a href="{{ route('password.change') }}" class="btn btn-light"><i class="bi bi-key"></i>Change password</a>
                <a href="#mobile-change" class="btn btn-light"><i class="bi bi-phone"></i>Change mobile</a>
                <form method="POST" action="{{ route('logout') }}" class="d-lg-none">@csrf<button class="btn btn-light text-danger w-100"><i class="bi bi-box-arrow-right"></i>Sign out</button></form>
            </div>
        </div>
    </section>

    <div class="row g-4">
        <div class="col-lg-7">
            {{-- ============ Details ============ --}}
            <section class="card pf-card mb-4">
                <div class="pf-card-head"><span class="pf-card-icon"><i class="bi bi-person-vcard"></i></span><div><h3>Personal details</h3><p>Shown on your inspection reports</p></div></div>
                <div class="pf-tiles">
                    <div class="pf-tile"><i class="bi bi-person-badge"></i><span>Employee ID</span><strong class="font-monospace">{{ $user->employee_id ?: '—' }}</strong></div>
                    <div class="pf-tile"><i class="bi bi-phone"></i><span>Mobile</span><strong>+91 {{ $user->mobile }}</strong></div>
                    <div class="pf-tile"><i class="bi bi-envelope"></i><span>Email</span><strong>{{ $user->email ?: 'Not added' }}</strong></div>
                    <div class="pf-tile"><i class="bi bi-briefcase"></i><span>Designation</span><strong>{{ $user->designation?->label ?? '—' }}</strong></div>
                    <div class="pf-tile wide"><i class="bi bi-geo-alt"></i><span>Jurisdiction</span><strong>{{ $jur?->label() ?: 'State' }}</strong></div>
                    @if ($user->directPermissions->isNotEmpty())
                        <div class="pf-tile wide"><i class="bi bi-shield-plus"></i><span>Extra rights</span><strong>{{ $user->directPermissions->pluck('name')->map(fn ($p) => str_replace('_', ' ', $p))->implode(', ') }}</strong></div>
                    @endif
                </div>
                <div class="pf-lock"><i class="bi bi-lock"></i>Your user level and jurisdiction can only be changed by the State Administrator.</div>
            </section>

            {{-- ============ Change mobile ============ --}}
            <section class="card pf-card mb-4" id="mobile-change">
                <div class="pf-card-head"><span class="pf-card-icon tone-green"><i class="bi bi-phone-vibrate"></i></span><div><h3>Change mobile number</h3><p>Current number <strong>+91 {{ $user->mobile }}</strong> · the State Administrator approves the change</p></div></div>
                <div class="pf-card-body">
                    @if ($pendingMobile)
                        <div class="pf-pending">
                            <span class="pf-pending-dot"></span>
                            <div><strong>Request pending approval</strong>
                                <div class="text-sm text-secondary">New number +91 {{ $pendingMobile->new_mobile }} · sent {{ $pendingMobile->created_at?->format('d M Y') }}</div></div>
                        </div>
                    @else
                        <form method="POST" action="{{ route('profile.mobile') }}" class="row g-3">
                            @csrf
                            <input type="hidden" name="current" value="{{ $user->mobile }}">
                            <div class="col-sm-6">
                                <label class="form-label" for="new_mobile">New mobile number</label>
                                <div class="input-group">
                                    <span class="input-group-text">+91</span>
                                    <input type="tel" inputmode="numeric" maxlength="10" pattern="[6-9][0-9]{9}" data-mobile name="new_mobile" id="new_mobile" value="{{ old('new_mobile') }}" placeholder="10-digit number"
                                           class="form-control @error('new_mobile') is-invalid @enderror" required>
                                    @error('new_mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="reason">Reason</label>
                                <input name="reason" id="reason" value="{{ old('reason') }}" placeholder="e.g. Old SIM lost" class="form-control @error('reason') is-invalid @enderror" maxlength="255" required>
                                @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12"><button class="btn btn-primary"><i class="bi bi-send"></i>Send request</button></div>
                        </form>
                    @endif
                </div>
            </section>
        </div>

        <div class="col-lg-5">
            {{-- ============ Activity ============ --}}
            @if ($canInspect)
                <section class="card pf-card mb-4">
                    <div class="pf-card-head"><span class="pf-card-icon tone-orange"><i class="bi bi-bar-chart"></i></span><div><h3>My inspections</h3><p>Your own inspections</p></div></div>
                    <div class="pf-stats">
                        <a href="{{ route('inspections.mine') }}" class="pf-stat"><b>{{ $activity['drafts'] }}</b><span>Open drafts</span></a>
                        <a href="{{ route('inspections.mine') }}" class="pf-stat"><b>{{ $activity['month'] }}</b><span>Submitted this month</span></a>
                        <a href="{{ route('inspections.mine') }}" class="pf-stat"><b>{{ $activity['total'] }}</b><span>Submitted in total</span></a>
                    </div>
                </section>
            @endif

            {{-- ============ Jurisdiction history ============ --}}
            <section class="card pf-card">
                <div class="pf-card-head"><span class="pf-card-icon"><i class="bi bi-signpost-split"></i></span><div><h3>Jurisdiction history</h3><p>Postings recorded for your account</p></div></div>
                <ol class="pf-timeline">
                    @foreach ($user->jurisdictions->sortByDesc('start_date') as $j)
                        <li class="{{ $j->end_date ? '' : 'is-current' }}">
                            <div class="fw-semibold">{{ $j->label() }}</div>
                            <div class="text-xs text-secondary">From {{ $j->start_date->format('d M Y') }}{{ $j->end_date ? ' to '.$j->end_date->format('d M Y') : '' }}</div>
                            @unless ($j->end_date)<span class="badge-soft badge-soft-success mt-1 d-inline-block">Current</span>@endunless
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>
    </div>
</x-layouts.app>
