{{-- 27-11-2026: "View identity proof" opens the proof in a popup (My Profile, admin user page).
     The file loads only when the popup opens ([data-lazy-src], app.js), so id_proof_viewed is logged only then. --}}
@if ($user->id_proof_path)
    @php
        $proofUrl = route('admin.users.id-proof', $user);
        $proofPdf = str_ends_with(strtolower($user->id_proof_path), '.pdf');
    @endphp
    <button type="button" class="btn btn-light {{ $class ?? '' }}" data-bs-toggle="modal" data-bs-target="#id-proof-modal" data-no-loader data-no-busy><i class="bi bi-person-vcard"></i>View identity proof</button>
    @push('scripts')
        <div class="modal fade" id="id-proof-modal" tabindex="-1" aria-labelledby="id-proof-title" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div class="min-w-0">
                            <h2 class="modal-title fs-6" id="id-proof-title"><i class="bi bi-person-vcard me-1 text-primary"></i>Identity proof</h2>
                            <div class="text-xs text-secondary text-truncate">{{ $user->name }}@if ($user->id_proof_original_name) · {{ $user->id_proof_original_name }}@endif</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body bg-light p-2 position-relative d-flex align-items-center justify-content-center" style="min-height:320px">
                        <div class="position-absolute top-50 start-50 translate-middle text-secondary text-sm" data-lazy-wait><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading…</div>
                        @if ($proofPdf)
                            <iframe data-lazy-src="{{ $proofUrl }}" title="Identity proof of {{ $user->name }}" class="w-100 rounded bg-white" style="height:70vh;border:0"></iframe>
                        @else
                            <img data-lazy-src="{{ $proofUrl }}" alt="Identity proof of {{ $user->name }}" class="img-fluid rounded" style="max-height:70vh">
                        @endif
                    </div>
                    <div class="modal-footer justify-content-between">
                        <span class="text-xs text-secondary">@if ($proofPdf)If the document does not show (some phones), use Open in new tab.@endif</span>
                        <div class="d-flex gap-2">
                            <a href="{{ $proofUrl }}" target="_blank" rel="noopener" class="btn btn-soft-primary" data-no-loader data-no-busy><i class="bi bi-box-arrow-up-right"></i>Open in new tab</a>
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endpush
@endif
