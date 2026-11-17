<x-layouts.app title="My Profile">
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card card-elevated mb-4">
                <div class="card-body text-center p-4">
                    <x-user-avatar :user="$user" size="xl" class="mb-3" />
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
