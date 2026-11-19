<x-layouts.app title="Review Registration" :back="route('admin.approvals.index')">
    @php $u = $registration->user; $jur = $u->currentJurisdiction; $isPdf = str_ends_with(strtolower($u->id_proof_original_name ?? ''), '.pdf'); @endphp
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card card-elevated">
                <div class="card-body d-flex align-items-center gap-3">
                    <x-user-avatar :user="$u" size="xl" />
                    <div>
                        <h2 class="h5 mb-0">{{ $u->name }}</h2>
                        <div class="text-secondary text-sm">{{ $u->designation_label }}</div>
                    </div>
                </div>
                <div class="card-body border-top">
                    <dl class="kv mb-0">
                        <dt>User level</dt><dd>{{ $u->level->label() }}</dd>
                        <dt>Jurisdiction</dt><dd>{{ $jur?->label() }}</dd>
                        <dt>Employee ID</dt><dd>@if ($u->employee_id){{ $u->employee_id }}@else<span class="text-secondary fst-italic">Not provided</span>@endif</dd>
                        <dt>Mobile</dt><dd>{{ $u->mobile }}</dd>
                        <dt>Email</dt><dd>{{ $u->email ?: '—' }}</dd>
                        <dt>Applied on</dt><dd>{{ $registration->created_at->format('d M Y, h:i A') }}</dd>
                    </dl>
                </div>
                @if ($history->isNotEmpty())
                    <div class="card-body border-top">
                        <div class="section-title">Previous applications</div>
                        @foreach ($history as $h)
                            <div class="text-sm mb-1"><x-status-badge :status="$h->status" /> {{ $h->created_at->format('d M Y') }} @if ($h->reason) · <em>{{ $h->reason }}</em>@endif</div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="card mt-4">
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.approvals.approve', $registration) }}" data-confirm="Approve {{ $u->name }}?">
                        @csrf
                        <button class="btn btn-success w-100 btn-lg"><i class="bi bi-check2-circle"></i>Approve registration</button>
                    </form>
                    <hr>
                    <form method="POST" action="{{ route('admin.approvals.reject', $registration) }}">
                        @csrf
                        <label class="form-label" for="reason">Reject with reason</label>
                        <textarea name="reason" id="reason" rows="3" class="form-control @error('reason') is-invalid @enderror" placeholder="e.g. Identity proof is not readable" required>{{ old('reason') }}</textarea>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <button class="btn btn-soft-danger w-100 mt-2"><i class="bi bi-x-circle"></i>Reject</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-person-vcard me-2 text-primary"></i>Identity proof</span>
                    @if ($u->id_proof_path)<a href="{{ route('admin.users.id-proof', $u) }}" target="_blank" rel="noopener" class="btn btn-sm btn-soft-primary"><i class="bi bi-box-arrow-up-right"></i>Open</a>@endif
                </div>
                <div class="card-body p-2 bg-light d-flex align-items-center justify-content-center" style="min-height:420px">
                    @if (! $u->id_proof_path)
                        <div class="text-center text-secondary p-4"><i class="bi bi-file-earmark-x fs-1 d-block mb-2"></i>No identity proof was uploaded with this registration (optional).<div class="text-xs mt-1">Verify the officer by other means before approving.</div></div>
                    @elseif ($isPdf)
                        <iframe src="{{ route('admin.users.id-proof', $u) }}" title="Identity proof" class="w-100 rounded" style="height:70vh;border:0"></iframe>
                    @else
                        <img src="{{ route('admin.users.id-proof', $u) }}" alt="Identity proof of {{ $u->name }}" class="img-fluid rounded" style="max-height:70vh">
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
