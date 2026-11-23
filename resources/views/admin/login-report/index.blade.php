{{-- V6 Module 6: login user monitoring report (State Administrator) --}}
@php use App\Models\UserLoginLog; use App\Services\LoginMonitorService as LM; $s = $summary; $keep = request()->except('status', 'page'); @endphp
<x-layouts.app title="Login Monitoring">
    <x-slot:actions>
        <a href="{{ route('admin.login-report.export', request()->query()) }}" class="btn btn-soft-primary d-none d-sm-inline-flex"><i class="bi bi-file-earmark-excel"></i>Export</a>
    </x-slot:actions>

    @include('admin.login-report.tabs', ['active' => 'log'])

    {{-- Modification V8 item 8: tiles keep their text inside the box (labels wrap, never cut off) --}}
    <div class="row g-2 g-md-3 mb-3 login-stats">
        <div class="col-6 col-md-4 col-xl-2"><x-stat-card wrap label="Sign-ins" :value="number_format($s['logins'])" icon="bi-box-arrow-in-right" tone="primary" hint="in the period" :href="route('admin.login-report.index', ['status' => 'success'] + $keep)" /></div>
        <div class="col-6 col-md-4 col-xl-2"><x-stat-card wrap label="Officers signed in" :value="number_format($s['users'])" icon="bi-people" tone="accent" hint="different officers" /></div>
        <div class="col-6 col-md-4 col-xl-2"><x-stat-card wrap label="Online now" :value="number_format($s['online'])" icon="bi-broadcast" tone="success" hint="active session" :href="route('admin.login-report.index', ['status' => 'online'] + $keep)" /></div>
        <div class="col-6 col-md-4 col-xl-2"><x-stat-card wrap label="Refused attempts" :value="number_format($s['refused'])" icon="bi-shield-exclamation" tone="danger" hint="wrong or blocked" :href="route('admin.login-report.index', ['status' => 'failed'] + $keep)" /></div>
        <div class="col-6 col-md-4 col-xl-2"><x-stat-card wrap label="Accounts locked now" :value="number_format($s['locked'])" icon="bi-lock" tone="warning" hint="after 3 failures" :href="route('admin.users.index', ['status' => 'locked'])" /></div>
        <div class="col-6 col-md-4 col-xl-2"><x-stat-card wrap label="Never signed in" :value="number_format($s['never'])" icon="bi-person-dash" tone="orange" hint="active officers" /></div>
    </div>

    <form method="GET" class="filter-bar mb-3" data-loader="heavy" data-loader-text="Loading login report…">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="f-from">From</label><input type="date" id="f-from" name="from" value="{{ $filters['from'] }}" class="form-control"></div>
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="f-to">To</label><input type="date" id="f-to" name="to" value="{{ $filters['to'] }}" class="form-control"></div>
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="f-level">Level</label>
                <select id="f-level" name="level" class="form-select" data-autosubmit><option value="">All levels</option>
                    @foreach ($levels as $l)<option value="{{ $l->value }}" @selected($filters['level'] === $l->value)>{{ $l->label() }}</option>@endforeach</select></div>
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="f-district">District</label>
                <select id="f-district" name="district_id" class="form-select" data-autosubmit><option value="">All districts</option>
                    @foreach ($districts as $d)<option value="{{ $d->id }}" @selected($filters['district_id'] === $d->id)>{{ $d->label }}</option>@endforeach</select></div>
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="f-status">Result</label>
                <select id="f-status" name="status" class="form-select" data-autosubmit><option value="">All</option>
                    @foreach (UserLoginLog::STATUSES + ['online' => 'Online now'] as $k => $label)<option value="{{ $k }}" @selected($filters['status'] === $k)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="f-q">Officer</label>
                <div class="input-group"><input id="f-q" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Name or mobile"><button class="btn btn-primary" aria-label="Search"><i class="bi bi-search"></i></button></div></div>
        </div>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-7">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-diagram-3 me-2 text-primary"></i>Officers who signed in, by level</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Level</th><th class="text-end">Active officers</th><th class="text-end">Signed in</th><th style="width:40%">Share</th></tr></thead>
                        <tbody>
                        @foreach ($s['by_level'] as $r)
                            @php $pct = $r['active'] ? min(100, (int) round($r['signed_in'] * 100 / $r['active'])) : 0; @endphp
                            <tr>
                                <td>{{ $r['level'] }}</td>
                                <td class="text-end">{{ number_format($r['active']) }}</td>
                                <td class="text-end">{{ number_format($r['signed_in']) }}</td>
                                <td><div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height:8px" role="progressbar" aria-label="{{ $r['level'] }} officers signed in" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width: {{ $pct }}%"></div></div>
                                    <span class="text-xs text-secondary text-end" style="min-width:2.5rem">{{ $pct }}%</span>
                                </div></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-shield-exclamation me-2 text-danger"></i>Most refused attempts</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Mobile</th><th class="text-end">Attempts</th><th class="text-end">IPs</th><th>Last</th></tr></thead>
                        <tbody>
                        @forelse ($s['top_refused'] as $r)
                            <tr>
                                <td class="font-monospace text-sm"><a href="{{ route('admin.login-report.index', ['q' => $r->mobile, 'status' => 'failed'] + request()->except('q', 'status', 'page')) }}">{{ $r->mobile }}</a></td>
                                <td class="text-end">{{ $r->attempts }}</td>
                                <td class="text-end">{{ $r->ips }}</td>
                                <td class="text-xs text-nowrap">{{ \Illuminate\Support\Carbon::parse($r->last_at)->format('d M, h:i A') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-secondary text-sm py-3 text-center">No refused attempts in this period.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-clock-history me-2 text-primary"></i>Sign-in log</span>
            <span class="text-xs text-secondary">{{ number_format($logs->total()) }} entries</span>
        </div>
        <div class="table-responsive d-none d-md-block">
            <table class="table align-middle mb-0">
                <thead><tr><th>Sign-in</th><th>Officer</th><th>Level / jurisdiction</th><th>Result</th><th>Session</th><th class="text-end">Duration</th><th>IP / device</th></tr></thead>
                <tbody>
                @forelse ($logs as $l)
                    @include('admin.login-report.row', ['l' => $l])
                @empty
                    <tr><td colspan="7"><x-empty-state icon="bi-person-lock" title="No sign-in activity" text="Change the dates or filters." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="list-card d-md-none">
            @forelse ($logs as $l)
                @php [$stLabel] = LM::stateLabel($l->sessionState()); @endphp
                <a href="{{ $l->user ? route('admin.login-report.user', $l->user) : '#' }}" class="list-item text-decoration-none">
                    @if ($l->user)<x-user-avatar :user="$l->user" />@else<span class="avatar"><i class="bi bi-question"></i></span>@endif
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate">{{ $l->user?->name ?? $l->mobile }}</div>
                        <div class="text-xs text-secondary text-truncate">{{ $l->login_at->format('d M, h:i A') }} · {{ $l->status === 'success' ? $stLabel.' · '.UserLoginLog::durationLabel($l->durationMinutes()) : $l->reason }}</div>
                    </div>
                    <span class="text-nowrap badge-soft badge-soft-{{ $l->status === 'success' ? 'success' : ($l->status === 'denied' ? 'warning' : 'danger') }}">{{ UserLoginLog::STATUSES[$l->status] ?? $l->status }}</span>
                </a>
            @empty
                <x-empty-state icon="bi-person-lock" title="No sign-in activity" />
            @endforelse
        </div>
    </div>
    <div class="mt-3">{{ $logs->links() }}</div>
</x-layouts.app>
