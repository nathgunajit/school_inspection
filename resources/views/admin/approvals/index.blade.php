<x-layouts.app title="Pending Approvals">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <p class="text-secondary mb-0">Registrations waiting for your decision. Check the identity proof before approving.</p>
        <span class="badge-soft badge-soft-warning">{{ $requests->count() }} pending</span>
    </div>

    <div class="card">
        <div class="list-card">
            @forelse ($requests as $r)
                @php $u = $r->user; $jur = $u->currentJurisdiction; @endphp
                <a href="{{ route('admin.approvals.show', $r) }}" class="list-item text-decoration-none">
                    <x-user-avatar :user="$u" />
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate">{{ $u->name }} <span class="text-secondary fw-normal text-sm">· {{ $u->designation_label }}</span></div>
                        <div class="text-xs text-secondary text-truncate">
                            <span class="badge-soft badge-soft-primary me-1">{{ $u->level->label() }}</span>
                            {{ $jur?->label() }} · {{ $u->mobile }}
                        </div>
                    </div>
                    <div class="text-end d-none d-sm-block">
                        <div class="text-xs text-secondary">Applied</div>
                        <div class="text-sm fw-semibold">{{ $r->created_at->diffForHumans() }}</div>
                    </div>
                    <i class="bi bi-chevron-right text-secondary"></i>
                </a>
            @empty
                <x-empty-state icon="bi-person-check" title="All caught up" text="There are no registrations waiting for your approval." />
            @endforelse
        </div>
    </div>
</x-layouts.app>
