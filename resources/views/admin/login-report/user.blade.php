{{-- V6 Module 6: one officer's sign-in history --}}
<x-layouts.app :title="'Sign-in history · '.$officer->name">
    <x-slot:actions>
        <a href="{{ route('admin.login-report.index', ['q' => $officer->mobile, 'from' => now()->subDays(30)->toDateString()]) }}" class="btn btn-light"><i class="bi bi-arrow-left"></i>Login report</a>
    </x-slot:actions>

    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <x-user-avatar :user="$officer" size="lg" />
            <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold fs-6">{{ $officer->name }} @if ($totals['online'])<span class="badge-soft badge-soft-success ms-1">Online</span>@endif</div>
                <div class="text-sm text-secondary">{{ $officer->designation_label }} · {{ $officer->level->label() }} · {{ $officer->currentJurisdiction?->label() }}</div>
                <div class="text-xs text-secondary">Mobile {{ $officer->mobile }} · Last sign-in {{ $officer->last_login_at?->format('d M Y, h:i A') ?? 'never' }}</div>
            </div>
            <x-status-badge :status="$officer->status" />
            @can('manage_users')<a href="{{ route('admin.users.show', $officer) }}" class="btn btn-sm btn-soft-primary">Manage user</a>@endcan
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><x-stat-card label="Sign-ins (all time)" :value="number_format($totals['logins'])" icon="bi-box-arrow-in-right" tone="primary" /></div>
        <div class="col-6 col-md-3"><x-stat-card label="Sign-ins (30 days)" :value="number_format($totals['last30'])" icon="bi-calendar-check" tone="success" /></div>
        <div class="col-6 col-md-3"><x-stat-card label="Refused attempts" :value="number_format($totals['refused'])" icon="bi-shield-exclamation" tone="danger" /></div>
        <div class="col-6 col-md-3"><x-stat-card label="Devices used" :value="$totals['devices']->count()" icon="bi-laptop" tone="info" /></div>
    </div>

    @if ($totals['devices']->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-laptop me-2 text-primary"></i>Devices</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Browser</th><th>Platform</th><th>Device</th><th class="text-end">Sign-ins</th><th>Last used</th></tr></thead>
                    <tbody>
                    @foreach ($totals['devices'] as $d)
                        <tr><td>{{ $d->browser }}</td><td>{{ $d->platform }}</td><td class="text-capitalize">{{ $d->device }}</td><td class="text-end">{{ $d->n }}</td><td class="text-sm">{{ \Illuminate\Support\Carbon::parse($d->last_at)->format('d M Y, h:i A') }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header"><i class="bi bi-clock-history me-2 text-primary"></i>Sign-in history</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Sign-in</th><th>Result</th><th>Session</th><th class="text-end">Duration</th><th>IP / device</th></tr></thead>
                <tbody>
                @forelse ($logs as $l)
                    @include('admin.login-report.row', ['l' => $l, 'hideOfficer' => true])
                @empty
                    <tr><td colspan="5"><x-empty-state icon="bi-person-lock" title="No sign-ins recorded" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $logs->links() }}</div>
</x-layouts.app>
