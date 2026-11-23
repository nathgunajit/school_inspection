{{-- Modification V8 item 7: daily login report, one row per active officer, with the current login status (State Administrator) --}}
@php
    use App\Services\LoginMonitorService as LM;
    $s = $summary;
    $date = \Illuminate\Support\Carbon::parse($filters['date']);
    $isToday = $date->isToday();
    $keep = request()->except('state', 'page');
    $time = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('h:i A') : '—';
    $stateOf = function ($u) use ($isToday) {
        [$label, $tone, $icon] = LM::DAILY_STATES[$u->current_state] ?? ['—', 'secondary', 'bi-dash'];
        if ($u->current_state === 'absent' && $isToday) $label = 'Not signed in today';
        return [$label, $tone, $icon];
    };
@endphp
<x-layouts.app title="Login Monitoring">
    <x-slot:actions>
        <span id="ld-actions" class="d-contents" data-filter-region><a href="{{ route('admin.login-report.daily.export', request()->query()) }}" class="btn btn-soft-primary d-none d-sm-inline-flex" data-no-loader><i class="bi bi-file-earmark-excel"></i>Export</a></span>
    </x-slot:actions>

    @include('admin.login-report.tabs', ['active' => 'daily'])

    <div id="ld-head" class="d-flex flex-wrap align-items-baseline gap-2 mb-2" data-filter-region>
        <h2 class="h6 mb-0">Daily report · {{ $date->format('l, d M Y') }}</h2>
        <span class="text-xs text-secondary">Current status as of {{ now()->format('h:i A') }} · {{ number_format($s['officers']) }} active officers</span>
    </div>

    <div id="ld-stats" class="row g-2 g-md-3 mb-3 login-stats" data-filter-region>
        <div class="col-6 col-md-3"><x-stat-card wrap label="Online now" :value="number_format($s['online'])" icon="bi-broadcast" tone="success" hint="session active" :href="route('admin.login-report.daily', ['state' => 'online'] + $keep)" /></div>
        <div class="col-6 col-md-3"><x-stat-card wrap label="Signed in {{ $isToday ? 'today' : 'that day' }}" :value="number_format($s['signed_in'])" icon="bi-box-arrow-in-right" tone="primary" :hint="$s['officers'] ? round($s['signed_in'] * 100 / $s['officers']).' % of officers' : null" /></div>
        <div class="col-6 col-md-3"><x-stat-card wrap label="Not signed in" :value="number_format($s['absent'])" icon="bi-person-dash" tone="orange" hint="no sign-in on the day" :href="route('admin.login-report.daily', ['state' => 'absent'] + $keep)" /></div>
        <div class="col-6 col-md-3"><x-stat-card wrap label="Refused attempts" :value="number_format($s['refused'])" icon="bi-shield-exclamation" tone="danger" hint="wrong or blocked" /></div>
    </div>

    <form method="GET" id="ld-form" class="filter-bar mb-3" data-filter data-filter-region data-loader-text="Loading daily report…">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="d-date">Date</label><input type="date" id="d-date" name="date" value="{{ $filters['date'] }}" max="{{ today()->toDateString() }}" class="form-control" data-autosubmit></div>
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="d-level">Level</label>
                <select id="d-level" name="level" class="form-select" data-autosubmit><option value="">All levels</option>
                    @foreach ($levels as $l)<option value="{{ $l->value }}" @selected($filters['level'] === $l->value)>{{ $l->label() }}</option>@endforeach</select></div>
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="d-district">District</label>
                <select id="d-district" name="district_id" class="form-select" data-autosubmit><option value="">All districts</option>
                    @foreach ($districts as $d)<option value="{{ $d->id }}" @selected($filters['district_id'] === $d->id)>{{ $d->label }}</option>@endforeach</select></div>
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="d-state">Current status</label>
                <select id="d-state" name="state" class="form-select" data-autosubmit><option value="">All</option>
                    @foreach (LM::DAILY_STATES as $k => [$label])<option value="{{ $k }}" @selected($filters['state'] === $k)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-12 col-md"><label class="form-label text-xs mb-1" for="d-q">Officer</label>
                <input id="d-q" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Name, mobile or employee ID"></div>
            <div class="col-12 col-md-auto"><x-filter-buttons /></div>
        </div>
    </form>

    <div id="ld-results" data-filter-region data-filter-results>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-calendar-day me-2 text-primary"></i>Officers on {{ $date->format('d M Y') }}</span>
            <span class="text-xs text-secondary">{{ number_format($rows->total()) }} officers</span>
        </div>
        <div class="table-responsive d-none d-md-block">
            <table class="table align-middle mb-0 daily-login-table">
                <thead><tr><th>Officer</th><th>Level / jurisdiction</th><th>Current status</th><th class="text-end">Sign-ins</th><th>First sign-in</th><th>Last activity</th><th class="text-end">Time online</th><th class="text-end">Refused</th></tr></thead>
                <tbody>
                @forelse ($rows as $u)
                    @php [$label, $tone, $icon] = $stateOf($u); @endphp
                    <tr>
                        <td><a href="{{ route('admin.login-report.user', $u) }}" class="d-flex align-items-center gap-2 text-decoration-none">
                            <x-user-avatar :user="$u" />
                            <div class="min-w-0"><div class="fw-semibold text-truncate">{{ $u->name }}</div><div class="text-xs text-secondary">{{ $u->mobile }}</div></div></a></td>
                        <td class="text-sm">{{ $u->level->label() }}<div class="text-xs text-secondary">{{ $u->currentJurisdiction?->label() }}</div></td>
                        <td><span class="badge-soft badge-soft-{{ $tone }} text-nowrap daily-state daily-state-{{ $u->current_state }}"><i class="bi {{ $icon }}"></i>{{ $label }}</span></td>
                        <td class="text-end">{{ (int) $u->sign_ins ?: '—' }}</td>
                        <td class="text-sm text-nowrap">{{ $time($u->first_in) }}</td>
                        <td class="text-sm text-nowrap">{{ $time($u->last_seen) }}</td>
                        <td class="text-end text-sm text-nowrap">{{ (int) $u->sign_ins ? \App\Models\UserLoginLog::durationLabel($minutes[$u->id] ?? 0) : '—' }}</td>
                        <td class="text-end {{ $u->refused ? 'text-danger fw-semibold' : 'text-secondary' }}">{{ (int) $u->refused ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-empty-state icon="bi-people" title="No officers match" text="Change the filters." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="list-card d-md-none">
            @forelse ($rows as $u)
                @php [$label, $tone, $icon] = $stateOf($u); @endphp
                <a href="{{ route('admin.login-report.user', $u) }}" class="list-item text-decoration-none">
                    <x-user-avatar :user="$u" />
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate">{{ $u->name }}</div>
                        <div class="text-xs text-secondary text-truncate">{{ $u->level->label() }} · {{ (int) $u->sign_ins ? $time($u->first_in).' – '.$time($u->last_seen) : 'no sign-in' }}</div>
                    </div>
                    <span class="text-nowrap badge-soft badge-soft-{{ $tone }}">{{ $label }}</span>
                </a>
            @empty
                <x-empty-state icon="bi-people" title="No officers match" />
            @endforelse
        </div>
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
    </div>
</x-layouts.app>
