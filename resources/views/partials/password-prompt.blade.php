{{-- 27-11-2026: first sign-in (or a temporary password): change the password now, or skip (not asked again) --}}
@if (session('pw_prompt') && auth()->check() && ! request()->routeIs('password.change*'))
    @php $temporary = auth()->user()->must_change_password; @endphp
    <div class="modal fade pw-prompt" id="pw-prompt" tabindex="-1" aria-labelledby="pw-prompt-title" data-reopen data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center p-4">
                    <span class="pw-prompt-icon"><i class="bi bi-shield-lock"></i></span>
                    <h2 class="h5 mt-3 mb-2" id="pw-prompt-title">Change your password?</h2>
                    <p class="pw-prompt-text">
                        @if ($temporary)
                            You signed in with a temporary password. Set your own password to keep your account safe.
                        @else
                            This is your first sign-in. You can set a new password now, or skip and change it later from My Profile.
                        @endif
                    </p>
                    <div class="pw-prompt-actions">
                        <a href="{{ route('password.change') }}" class="btn btn-primary"><i class="bi bi-key"></i>Change password</a>
                        <form method="POST" action="{{ route('password.skip') }}">
                            @csrf
                            <button type="submit" class="btn btn-light w-100" data-loading-text="Skipping…">Skip for now</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif
