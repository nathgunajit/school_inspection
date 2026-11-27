<x-layouts.app title="Change Password" :back="route('profile')">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card card-elevated">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="stat-icon tone-primary" style="width:46px;height:46px;border-radius:12px;display:grid;place-items:center"><i class="bi bi-key fs-5"></i></div>
                        <div>
                            <h2 class="h5 mb-0">Set a new password</h2>
                            <div class="text-secondary text-sm">At least 8 characters with upper case, lower case, digit and special character.</div>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('password.change.update') }}">
                        @csrf @method('PUT')
                        <div class="mb-3">
                            <label class="form-label" for="current_password">Current password</label>
                            <input type="password" name="current_password" id="current_password" class="form-control @error('current_password') is-invalid @enderror" required autocomplete="current-password">
                            @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password">New password</label>
                            <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror" required autocomplete="new-password">
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="password_confirmation">Confirm new password</label>
                            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" required autocomplete="new-password">
                        </div>
                        <button class="btn btn-primary w-100" data-loading-text="Updating password…"><i class="bi bi-check2"></i>Update password</button>
                    </form>
                    @if (session('pw_prompt'))
                        {{-- 27-11-2026: first sign-in: the change may be skipped (not asked again) --}}
                        <form method="POST" action="{{ route('password.skip') }}" class="mt-2">
                            @csrf
                            <button type="submit" class="btn btn-light w-100" data-loading-text="Skipping…">Skip for now</button>
                        </form>
                        <p class="text-center text-secondary text-xs mt-2 mb-0">You can change it later from My Profile.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
