@php
    $jur = $user->jurisdictions->firstWhere('end_date', null);
    $canInspect = $user->can('conduct_inspections');
@endphp
<x-layouts.app title="My Profile">
    {{-- ============ Hero: photo, name, role (Modification V3 item 1, restyled in V9) ============ --}}
    <section class="pf-hero">
        <div class="pf-hero-band" aria-hidden="true">
            <span class="pf-hero-ring r1"></span><span class="pf-hero-ring r2"></span>
            <img src="{{ asset('logo/SSA LOGO.png') }}" alt="" class="pf-hero-mark">
            <div class="pf-hero-greet"><span>{{ now()->hour < 12 ? 'Good morning' : (now()->hour < 17 ? 'Good afternoon' : 'Good evening') }}</span>Samagra Shiksha Axom</div>
        </div>
        <div class="pf-hero-body">
            {{-- 27-11-2026: photo restyled (white ring + soft halo, hover "Change photo", status dot, dashed ring when empty, orange "Not saved" preview) --}}
            @php
                $photoLabel = $user->photo_path ? 'Change photo' : 'Upload photo';
                $statusDot = match ($user->status) { \App\Enums\UserStatus::Active => 'is-active', \App\Enums\UserStatus::Pending => 'is-pending', default => null };
            @endphp
            <form method="POST" action="{{ route('profile.photo') }}" enctype="multipart/form-data" class="profile-photo pf-photo {{ $user->photo_path ? '' : 'is-empty' }}" data-photo-form
                  data-max-kb="{{ config('inspection.employee_photo.max_kb') }}" data-max-side="{{ config('inspection.employee_photo.max_side_px') }}" data-short-side="{{ config('inspection.employee_photo.short_side_px') }}">
                @csrf
                <div class="profile-photo-frame">
                    <x-user-avatar :user="$user" size="xl" data-photo-current />
                    <img alt="New photo preview" class="avatar avatar-xl avatar-img d-none" data-photo-preview>
                    <label class="pf-photo-overlay" for="profile-photo-input" aria-hidden="true"><i class="bi bi-camera"></i>{{ $photoLabel }}</label>
                    @if ($statusDot)<span class="pf-photo-dot {{ $statusDot }}" aria-hidden="true"><i class="bi {{ $statusDot === 'is-active' ? 'bi-check-lg' : 'bi-hourglass-split' }}"></i></span>@endif
                    <label class="profile-photo-btn" for="profile-photo-input" title="{{ $photoLabel }}">
                        <i class="bi bi-camera-fill"></i><span class="visually-hidden">{{ $photoLabel }}</span>
                    </label>
                    <input type="file" id="profile-photo-input" name="photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="d-none">
                </div>
                <span class="pf-photo-unsaved"><i class="bi bi-dot"></i>New photo · not saved yet</span>
                @unless ($user->photo_path)
                    <label class="pf-photo-hint" for="profile-photo-input" data-photo-hint>
                        <i class="bi bi-camera"></i>
                        <span><strong>Add your passport-size photo</strong><small>JPG, JPEG or PNG, max {{ \App\Support\FileSize::human(config('inspection.employee_photo.max_kb')) }}</small></span>
                    </label>
                @endunless
                <div class="pf-photo-error d-none" data-photo-error></div>
                @error('photo')<div class="pf-photo-error">{{ $message }}</div>@enderror
                <div class="pf-photo-actions d-none" data-photo-actions>
                    <button type="button" class="btn btn-light btn-sm" data-photo-cancel><i class="bi bi-x-lg"></i>Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2"></i>Save photo</button>
                </div>
            </form>

            <div class="pf-identity">
                <h2 class="pf-name">{{ $user->name }}</h2>
                <div class="pf-role">{{ $user->designation_label ?? 'Officer' }}</div>
                <div class="pf-chips">
                    <span class="pf-chip level"><i class="bi bi-diagram-3"></i>{{ $user->level->label() }} level</span>
                    <x-status-badge :status="$user->status" />
                    @if ($jur)<span class="pf-chip"><i class="bi bi-geo-alt"></i>{{ $jur->label() }}</span>@endif
                </div>
            </div>

            <div class="pf-actions">
                <a href="{{ route('password.change') }}" class="btn btn-light"><i class="bi bi-key"></i>Change password</a>
                <a href="#mobile-change" class="btn btn-light" data-pf-open-mobile><i class="bi bi-phone"></i>Change mobile</a>
                <form method="POST" action="{{ route('logout') }}" class="d-lg-none" data-si-signout>@csrf<button class="btn btn-light text-danger w-100"><i class="bi bi-box-arrow-right"></i>Sign out</button></form>
            </div>
        </div>

        {{-- V9: quick facts strip --}}
        <div class="pf-hero-meta">
            @if ($canInspect)
                <div class="pf-meta"><span class="pf-meta-icon tone-green"><i class="bi bi-clipboard-check"></i></span><div><b>{{ number_format($activity['total']) }}</b><span>Inspections submitted</span></div></div>
                <div class="pf-meta"><span class="pf-meta-icon tone-blue"><i class="bi bi-calendar3"></i></span><div><b>{{ number_format($activity['month']) }}</b><span>This month</span></div></div>
            @endif
            <div class="pf-meta"><span class="pf-meta-icon tone-orange"><i class="bi bi-calendar-check"></i></span><div><b>{{ $user->created_at?->format('M Y') ?? '—' }}</b><span>Member since</span></div></div>
            <div class="pf-meta"><span class="pf-meta-icon tone-slate"><i class="bi bi-clock-history"></i></span><div><b>{{ $user->last_login_at?->format('d M Y, h:i A') ?? '—' }}</b><span>Last sign-in</span></div></div>
        </div>
    </section>

    {{-- 27-11-2026: cards below the hero restyled (tone accents, clean field list, folding mobile form) --}}
    <div class="row g-4 align-items-start">
        <div class="col-lg-7 d-flex flex-column gap-4">
            {{-- ============ Details ============ --}}
            <section class="card pf-card tone-blue">
                <div class="pf-card-head"><span class="pf-card-icon"><i class="bi bi-person-vcard"></i></span><div><h3>Personal details</h3><p>Shown on your inspection reports</p></div></div>
                <dl class="pf-fields">
                    <div class="pf-field"><i class="bi bi-person-badge"></i><dt>Employee ID</dt><dd class="font-monospace {{ $user->employee_id ? '' : 'is-empty' }}">{{ $user->employee_id ?: 'Not added' }}</dd></div>
                    <div class="pf-field"><i class="bi bi-phone"></i><dt>Mobile</dt><dd>+91 {{ $user->mobile }}@unless ($pendingMobile) <a href="#mobile-change" class="pf-field-link" data-pf-open-mobile>Change</a>@endunless</dd></div>
                    <div class="pf-field"><i class="bi bi-envelope"></i><dt>Email</dt><dd class="{{ $user->email ? '' : 'is-empty' }}">{{ $user->email ?: 'Not added' }}</dd></div>
                    <div class="pf-field"><i class="bi bi-briefcase"></i><dt>Designation</dt><dd class="{{ $user->designation_label ? '' : 'is-empty' }}">{{ $user->designation_label ?? '—' }}</dd></div>
                    <div class="pf-field"><i class="bi bi-gender-ambiguous"></i><dt>Gender</dt><dd class="{{ $user->gender ? '' : 'is-empty' }}">{{ \App\Models\User::GENDERS[$user->gender] ?? 'Not added' }}</dd></div>
                    <div class="pf-field"><i class="bi bi-calendar-event"></i><dt>Date of birth</dt><dd class="{{ $user->date_of_birth ? '' : 'is-empty' }}">{{ $user->date_of_birth?->format('d M Y') ?? 'Not added' }}</dd></div>
                    <div class="pf-field wide"><i class="bi bi-geo-alt"></i><dt>Jurisdiction</dt><dd>{{ $jur?->label() ?: 'State' }}</dd></div>
                    @if ($user->directPermissions->isNotEmpty())
                        <div class="pf-field wide"><i class="bi bi-shield-plus"></i><dt>Extra rights</dt><dd class="text-capitalize">{{ $user->directPermissions->pluck('name')->map(fn ($p) => str_replace('_', ' ', $p))->implode(', ') }}</dd></div>
                    @endif
                </dl>
                <div class="pf-card-foot"><i class="bi bi-lock"></i>Your user level and jurisdiction can only be changed by the State Administrator.</div>
            </section>

            {{-- ============ Change mobile (the form folds open from "Request a new number") ============ --}}
            @php $mobileOpen = $errors->has('new_mobile') || $errors->has('reason') || old('new_mobile'); @endphp
            <section class="card pf-card tone-green" id="mobile-change">
                <div class="pf-card-head"><span class="pf-card-icon"><i class="bi bi-phone-vibrate"></i></span><div><h3>Change mobile number</h3><p>The State Administrator approves the change</p></div></div>
                <div class="pf-card-body">
                    @if ($pendingMobile)
                        <div class="pf-pending">
                            <span class="pf-pending-dot"></span>
                            <div><strong>Request pending approval</strong>
                                <div class="text-sm text-secondary">New number +91 {{ $pendingMobile->new_mobile }} · sent {{ $pendingMobile->created_at?->format('d M Y') }}</div></div>
                        </div>
                    @else
                        <div class="pf-mobile-now {{ $mobileOpen ? 'd-none' : '' }}" data-pf-mobile-now>
                            <div><span>Current number</span><strong>+91 {{ $user->mobile }}</strong></div>
                            <button type="button" class="btn btn-soft-primary" data-pf-open-mobile><i class="bi bi-pencil-square"></i>Request a new number</button>
                        </div>
                        <div class="collapse {{ $mobileOpen ? 'show' : '' }}" id="mobile-form">
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
                            <div class="col-12 d-flex gap-2 justify-content-end">
                                <button type="button" class="btn btn-light" data-pf-close-mobile data-no-busy>Cancel</button>
                                <button class="btn btn-primary"><i class="bi bi-send"></i>Send request</button>
                            </div>
                        </form>
                        </div>
                    @endif
                </div>
            </section>
        </div>

        <div class="col-lg-5 d-flex flex-column gap-4">
            {{-- ============ Identity proof (27-11-2026: shown on the page; tap to enlarge in the popup) ============ --}}
            @php $proofPdf = $user->id_proof_path && str_ends_with(strtolower($user->id_proof_path), '.pdf'); @endphp
            <section class="card pf-card tone-purple">
                <div class="pf-card-head"><span class="pf-card-icon"><i class="bi bi-person-vcard"></i></span><div><h3>Identity proof</h3><p>Optional · visible to you and your approving officers</p></div></div>
                <div class="pf-card-body">
                    @if (! $user->id_proof_path)
                        <div class="pf-proof-empty"><i class="bi bi-file-earmark-person"></i>No identity proof uploaded (optional).</div>
                    @elseif ($proofPdf)
                        <div class="pf-proof-doc">
                            <i class="bi bi-file-earmark-pdf"></i>
                            <div class="min-w-0"><strong class="text-truncate d-block">{{ $user->id_proof_original_name ?: 'Identity proof.pdf' }}</strong><span>PDF document</span></div>
                            <button type="button" class="btn btn-soft-primary btn-sm" data-bs-toggle="modal" data-bs-target="#id-proof-modal" data-no-loader data-no-busy><i class="bi bi-eye"></i>View</button>
                        </div>
                    @else
                        <button type="button" class="pf-proof" data-bs-toggle="modal" data-bs-target="#id-proof-modal" data-no-loader data-no-busy aria-label="Enlarge identity proof">
                            <img src="{{ route('admin.users.id-proof', $user) }}" alt="Identity proof of {{ $user->name }}" loading="lazy" data-proof-preview>
                            <span class="pf-proof-zoom"><i class="bi bi-zoom-in"></i>Tap to enlarge</span>
                        </button>
                        @if ($user->id_proof_original_name)<div class="pf-proof-name"><i class="bi bi-paperclip"></i><span class="text-truncate">{{ $user->id_proof_original_name }}</span></div>@endif
                    @endif
                </div>
                @include('partials.id-proof-modal', ['user' => $user, 'trigger' => false])
            </section>

            {{-- ============ Activity ============ --}}
            @if ($canInspect)
                <section class="card pf-card tone-orange">
                    <div class="pf-card-head"><span class="pf-card-icon"><i class="bi bi-bar-chart"></i></span><div><h3>My inspections</h3><p>Your own inspections</p></div>
                        <a href="{{ route('inspections.mine') }}" class="pf-head-link ms-auto">View all<i class="bi bi-arrow-right"></i></a></div>
                    <div class="pf-stats">
                        <a href="{{ route('inspections.mine') }}" class="pf-stat tone-orange"><i class="bi bi-pencil-square"></i><b>{{ $activity['drafts'] }}</b><span>Open drafts</span></a>
                        <a href="{{ route('inspections.mine') }}" class="pf-stat tone-blue"><i class="bi bi-calendar3"></i><b>{{ $activity['month'] }}</b><span>Submitted this month</span></a>
                        <a href="{{ route('inspections.mine') }}" class="pf-stat tone-green"><i class="bi bi-check2-circle"></i><b>{{ $activity['total'] }}</b><span>Submitted in total</span></a>
                    </div>
                </section>
            @endif

            {{-- ============ Jurisdiction history ============ --}}
            <section class="card pf-card tone-slate">
                <div class="pf-card-head"><span class="pf-card-icon"><i class="bi bi-signpost-split"></i></span><div><h3>Jurisdiction history</h3><p>Postings recorded for your account</p></div></div>
                <ol class="pf-timeline">
                    @foreach ($user->jurisdictions->sortByDesc('start_date') as $j)
                        <li class="{{ $j->end_date ? '' : 'is-current' }}">
                            <div class="min-w-0">
                                <div class="pf-tl-name">{{ $j->label() }}</div>
                                <div class="pf-tl-date"><i class="bi bi-calendar3"></i>{{ $j->start_date->format('d M Y') }} → {{ $j->end_date ? $j->end_date->format('d M Y') : 'Present' }}</div>
                            </div>
                            @unless ($j->end_date)<span class="badge-soft badge-soft-success flex-shrink-0">Current</span>@endunless
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>
    </div>
</x-layouts.app>
