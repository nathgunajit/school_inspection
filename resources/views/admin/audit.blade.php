<x-layouts.app title="Audit Log">
    {{-- Modification V13 item 4: audit and cache clean-up (also runs automatically on the 1st of every month) --}}
    <div class="card mb-3" id="cleanup">
        <div class="card-header d-flex flex-wrap align-items-center gap-2"><i class="bi bi-recycle text-primary"></i>Clean up
            <span class="text-xs text-secondary ms-auto">Runs automatically on the 1st of each month: entries older than {{ config('inspection.audit_retention_days') }} days and the cache.</span></div>
        <div class="card-body row g-3 align-items-end">
            <form method="POST" action="{{ route('admin.audit.purge') }}" class="col-lg-6 row g-2 align-items-end" data-confirm="Delete the audit entries older than this many days? A copy is saved as an Excel file first.">
                @csrf
                <div class="col-sm-7">
                    <label class="form-label" for="purge-days">Delete audit entries older than (days)</label>
                    <input id="purge-days" type="number" name="days" min="0" max="3650" value="{{ old('days', config('inspection.audit_retention_days')) }}" class="form-control @error('days') is-invalid @enderror" required>
                    @error('days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">{{ number_format($oldCount) }} entr{{ $oldCount === 1 ? 'y is' : 'ies are' }} older than {{ config('inspection.audit_retention_days') }} days.</div>
                </div>
                <div class="col-sm-5 d-grid mb-sm-4"><button class="btn btn-soft-danger" data-loading-text="Cleaning…"><i class="bi bi-trash"></i>Archive and delete</button></div>
            </form>
            <form method="POST" action="{{ route('admin.audit.cache') }}" class="col-lg-3 d-grid mb-lg-4" data-confirm="Clear the application cache now?">
                @csrf
                <button class="btn btn-soft-primary" data-loading-text="Clearing…"><i class="bi bi-lightning-charge"></i>Clear cache</button>
            </form>
            <div class="col-lg-3 mb-lg-4">
                <div class="text-xs text-secondary mb-1">Archives (Excel copies of deleted entries)</div>
                @forelse (array_slice($archives, 0, 5) as $a)
                    <div class="text-sm text-truncate"><a href="{{ route('admin.audit.archive', $a['name']) }}" data-no-loader><i class="bi bi-file-earmark-excel me-1"></i>{{ $a['time']->format('d M Y, h:i A') }}</a> <span class="text-xs text-secondary">{{ \App\Support\FileSize::human((int) ceil($a['size'] / 1024)) }}</span></div>
                @empty
                    <div class="text-sm text-secondary">None yet</div>
                @endforelse
            </div>
        </div>
    </div>

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
