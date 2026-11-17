<x-layouts.app title="Audit Log">
    <form method="GET" class="filter-bar mb-3 row g-2">
        <div class="col-12 col-md-3"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="User name or mobile"></div>
        <div class="col-6 col-md-2"><select name="action" class="form-select" data-autosubmit aria-label="Action"><option value="">All actions</option>
            @foreach ($actions as $a)<option value="{{ $a }}" @selected(request('action') === $a)>{{ str_replace('_', ' ', $a) }}</option>@endforeach</select></div>
        <div class="col-6 col-md-2"><input name="ip" value="{{ request('ip') }}" class="form-control" placeholder="IP address"></div>
        <div class="col-6 col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control" aria-label="From"></div>
        <div class="col-6 col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control" aria-label="To"></div>
        <div class="col-12 col-md-1 d-grid"><button class="btn btn-primary"><i class="bi bi-search"></i></button></div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Date &amp; time</th><th>User</th><th>Action</th><th>Record</th><th class="d-none d-lg-table-cell">Details</th><th>IP</th></tr></thead>
                <tbody>
                @forelse ($logs as $l)
                    <tr>
                        <td class="text-nowrap text-sm">{{ $l->created_at->format('d M Y, h:i:s A') }}</td>
                        <td class="text-sm">{{ $l->user?->name ?? '—' }}<div class="text-xs text-secondary">{{ $l->user?->mobile }}</div></td>
                        <td><span class="badge-soft badge-soft-{{ str_contains($l->action, 'fail') || str_contains($l->action, 'reject') || str_contains($l->action, 'unlock') ? 'danger' : (str_contains($l->action, 'login') || str_contains($l->action, 'logout') ? 'secondary' : 'primary') }}">{{ str_replace('_', ' ', $l->action) }}</span></td>
                        <td class="text-sm">{{ $l->auditable_type ? $l->auditable_type.' #'.$l->auditable_id : '—' }}</td>
                        <td class="d-none d-lg-table-cell text-xs text-secondary" style="max-width:320px"><div class="text-truncate">{{ $l->meta ? json_encode($l->meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '' }}</div></td>
                        <td class="text-xs font-monospace">{{ $l->ip_address }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty-state icon="bi-shield-check" title="No audit entries" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $logs->links() }}</div>
</x-layouts.app>
