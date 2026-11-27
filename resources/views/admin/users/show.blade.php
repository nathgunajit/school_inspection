<x-layouts.app :title="$user->name" :back="route('admin.users.index')">
    @php $admin = auth()->user()->isStateAdmin(); $cur = $user->jurisdictions->firstWhere('end_date', null); @endphp
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card card-elevated">
                <div class="card-body text-center p-4">
                    <x-user-avatar :user="$user" size="xl" class="mb-3" />
                    <h2 class="h5 mb-0">{{ $user->name }}</h2>
                    <div class="text-secondary text-sm mb-2">{{ $user->designation_label }}</div>
                    <x-status-badge :status="$user->status" />
                    <span class="badge-soft badge-soft-primary ms-1">{{ $user->level->label() }}</span>
                </div>
                <div class="card-body border-top">
                    <dl class="kv mb-0">
                        <dt>Employee ID</dt><dd>@if ($user->employee_id){{ $user->employee_id }}@else<span class="text-secondary fst-italic">Not provided</span>@endif</dd>
                        <dt>Date of birth</dt><dd>@if ($user->date_of_birth){{ $user->date_of_birth->format('d M Y') }}@else<span class="text-secondary fst-italic">Not provided</span>@endif</dd>
                        <dt>Mobile</dt><dd>{{ $user->mobile }}</dd>
                        <dt>Email</dt><dd>{{ $user->email ?: '—' }}</dd>
                        <dt>Jurisdiction</dt><dd>{{ $cur?->label() }}</dd>
                        <dt>Inspections</dt><dd>{{ $inspectionCount }}</dd>
                        <dt>Last login</dt><dd>{{ $user->last_login_at?->diffForHumans() ?? 'Never' }}</dd>
                    </dl>
                </div>
                <div class="card-footer bg-white d-grid gap-2">
                    @include('partials.id-proof-modal', ['user' => $user])
                    @if (in_array($user->status->value, ['active', 'inactive', 'locked']))
                        <form method="POST" action="{{ route('admin.users.status', $user) }}" class="d-grid" data-confirm="{{ $user->status->value === 'inactive' ? 'Activate' : 'Deactivate' }} this user?">
                            @csrf @method('PATCH')
                            @if ($user->status->value === 'inactive')
                                <button class="btn btn-success"><i class="bi bi-toggle-on"></i>Activate</button>
                            @else
                                <button class="btn btn-soft-danger"><i class="bi bi-toggle-off"></i>Deactivate</button>
                            @endif
                        </form>
                    @endif
                    @if ($admin)
                        <form method="POST" action="{{ route('admin.users.reset-password', $user) }}" class="d-grid" data-confirm="Generate a temporary password for this user?">
                            @csrf
                            <button class="btn btn-soft-primary"><i class="bi bi-key"></i>Reset password</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            @if ($admin)
                <div class="card mb-4">
                    <div class="card-header"><i class="bi bi-pencil me-2 text-primary"></i>Edit details</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.users.update', $user) }}" class="row g-3">
                            @csrf @method('PUT')
                            <div class="col-md-6"><label class="form-label">Full name</label><input name="name" value="{{ old('name', $user->name) }}" class="form-control @error('name') is-invalid @enderror" required></div>
                            <div class="col-6 col-md-3"><label class="form-label">Gender</label>
                                <select name="gender" class="form-select" aria-label="Gender"><option value="">Not given</option>@foreach (\App\Models\User::GENDERS as $k => $l)<option value="{{ $k }}" @selected(old('gender', $user->gender) === $k)>{{ $l }}</option>@endforeach</select></div>
                            <div class="col-6 col-md-3"><label class="form-label" for="u_dob">Date of birth</label>
                                <input type="date" id="u_dob" name="date_of_birth" value="{{ old('date_of_birth', $user->date_of_birth?->toDateString()) }}" min="{{ now()->subYears(\App\Models\User::MAX_AGE)->toDateString() }}" max="{{ now()->subYears(\App\Models\User::MIN_AGE)->toDateString() }}" class="form-control @error('date_of_birth') is-invalid @enderror">
                                @error('date_of_birth')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="col-md-6"><label class="form-label">Designation</label>
                                <select name="designation_id" class="form-select" data-other-value="{{ $otherDesignationId }}" data-other-target="#designation-other-wrap">@foreach ($designations as $d)<option value="{{ $d->id }}" @selected(old('designation_id', $user->designation_id) == $d->id)>{{ $d->label }}</option>@endforeach</select>
                                <div id="designation-other-wrap" class="mt-2 {{ old('designation_id', $user->designation_id) == $otherDesignationId ? '' : 'd-none' }}">
                                    <input name="designation_other" value="{{ old('designation_other', $user->designation_other) }}" maxlength="120" placeholder="Type the designation" aria-label="Designation (other)" class="form-control @error('designation_other') is-invalid @enderror">
                                    @error('designation_other')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div></div>
                            <div class="col-md-6"><label class="form-label">Employee ID</label><input name="employee_id" value="{{ old('employee_id', $user->employee_id) }}" class="form-control @error('employee_id') is-invalid @enderror" maxlength="40" pattern="[A-Za-z0-9/._\-]+" placeholder="Optional">
                                @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" value="{{ old('email', $user->email) }}" maxlength="150" class="form-control @error('email') is-invalid @enderror">@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check2"></i>Save changes</button></div>
                        </form>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header"><i class="bi bi-arrow-left-right me-2 text-primary"></i>Change jurisdiction (transfer)</div>
                    <div class="card-body">
                        <p class="text-sm text-secondary">The current jurisdiction ends the day before the effective date. Past inspections stay with the old jurisdiction.</p>
                        <form method="POST" action="{{ route('admin.users.transfer', $user) }}" class="row g-3">
                            @csrf
                            <div class="col-md-6 col-xl-3"><label class="form-label">State</label>
                                <select name="state_id" id="t_state" class="form-select" data-cascade-child="#t_district" data-cascade-url="{{ route('locations', 'districts') }}">
                                    <option value="">Select</option>@foreach ($states as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
                            @if ($user->level->needsDistrict())
                                <div class="col-md-6 col-xl-3"><label class="form-label">District</label>
                                    <select name="district_id" id="t_district" class="form-select @error('district_id') is-invalid @enderror" data-placeholder="Select" @if ($user->level->needsBlock()) data-cascade-child="#t_block" data-cascade-url="{{ route('locations', 'blocks') }}" @endif disabled><option value="">Select</option></select>
                                    @error('district_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            @endif
                            @if ($user->level->needsBlock())
                                <div class="col-md-6 col-xl-3"><label class="form-label">Block</label>
                                    <select name="block_id" id="t_block" class="form-select" data-placeholder="Select" @if ($user->level->needsCluster()) data-cascade-child="#t_cluster" data-cascade-url="{{ route('locations', 'clusters') }}" @endif disabled><option value="">Select</option></select></div>
                            @endif
                            @if ($user->level->needsCluster())
                                <div class="col-md-6 col-xl-3"><label class="form-label">Cluster</label>
                                    <select name="cluster_id" id="t_cluster" class="form-select" data-placeholder="Select" disabled><option value="">Select</option></select></div>
                            @endif
                            <div class="col-md-6 col-xl-3"><label class="form-label">Effective from</label><input type="date" name="effective_date" value="{{ now()->toDateString() }}" min="{{ now()->toDateString() }}" class="form-control @error('effective_date') is-invalid @enderror" required></div>
                            <div class="col-12"><button class="btn btn-primary" data-confirm><i class="bi bi-arrow-left-right"></i>Transfer</button></div>
                        </form>
                    </div>
                </div>

                @if ($grantable->isNotEmpty())
                    <div class="card mb-4">
                        <div class="card-header"><i class="bi bi-shield-plus me-2 text-primary"></i>Additional rights ("If permitted")</div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.users.permissions', $user) }}">
                                @csrf @method('PUT')
                                @foreach ($grantable as $p)
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" role="switch" name="permissions[]" value="{{ $p->slug }}" id="perm_{{ $p->slug }}" @checked($user->directPermissions->contains('slug', $p->slug))>
                                        <label class="form-check-label" for="perm_{{ $p->slug }}">{{ $p->name }}</label>
                                    </div>
                                @endforeach
                                <button class="btn btn-primary mt-2"><i class="bi bi-check2"></i>Save rights</button>
                            </form>
                        </div>
                    </div>
                @endif
            @endif

            <div class="card">
                <div class="card-header"><i class="bi bi-clock-history me-2 text-primary"></i>Jurisdiction history</div>
                <div class="list-card">
                    @foreach ($user->jurisdictions as $jur)
                        <div class="list-item">
                            <i class="bi bi-geo-alt text-secondary"></i>
                            <div class="flex-grow-1"><div class="fw-semibold">{{ $jur->label() }}</div>
                                <div class="text-xs text-secondary">From {{ $jur->start_date->format('d M Y') }} {{ $jur->end_date ? 'to '.$jur->end_date->format('d M Y') : '' }}</div></div>
                            @if (! $jur->end_date)<span class="badge-soft badge-soft-success">Current</span>@endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
