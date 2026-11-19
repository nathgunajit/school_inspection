<x-layouts.app title="Mobile Number Changes">
    <div class="card">
        <div class="list-card">
            @forelse ($requests as $r)
                <div class="list-item flex-wrap">
                    <x-user-avatar :user="$r->user" />
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold">{{ $r->user->name }} <span class="text-secondary fw-normal text-sm">· {{ $r->user->designation_label }}</span></div>
                        <div class="text-sm"><span class="text-secondary">{{ $r->old_mobile }}</span> <i class="bi bi-arrow-right mx-1"></i> <strong>{{ $r->new_mobile }}</strong></div>
                        <div class="text-xs text-secondary">{{ $r->reason }} · {{ $r->created_at->format('d M Y') }}</div>
                    </div>
                    @if ($r->status === 'pending')
                        <div class="d-flex gap-2">
                            <form method="POST" action="{{ route('admin.mobile-requests.decide', $r) }}">@csrf<input type="hidden" name="decision" value="approved"><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i>Approve</button></form>
                            <form method="POST" action="{{ route('admin.mobile-requests.decide', $r) }}">@csrf<input type="hidden" name="decision" value="rejected"><button class="btn btn-sm btn-soft-danger">Reject</button></form>
                        </div>
                    @else
                        <span class="badge-soft badge-soft-{{ $r->status === 'approved' ? 'success' : 'danger' }}">{{ ucfirst($r->status) }}</span>
                    @endif
                </div>
            @empty
                <x-empty-state icon="bi-phone" title="No mobile change requests" />
            @endforelse
        </div>
    </div>
    <div class="mt-3">{{ $requests->links() }}</div>
</x-layouts.app>
