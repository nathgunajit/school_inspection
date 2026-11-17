<x-layouts.app title="Dashboard">
    <div class="d-flex align-items-end justify-content-between gap-2 mb-3">
        <div class="min-w-0">
            <div class="text-xs text-secondary">{{ now()->format('l, d M Y') }}</div>
            <h2 class="h5 mb-0 text-truncate">Hello, {{ \Illuminate\Support\Str::of($user->name)->before(' ') }}</h2>
            <div class="text-xs text-secondary text-truncate">{{ $jurisdiction?->label() ?: $user->level->label() }}</div>
        </div>
    </div>

    @if ($pending)
        <a href="{{ route('admin.approvals.index') }}" class="alert alert-warning border-0 d-flex align-items-center gap-2 text-decoration-none py-2 mb-3 text-sm">
            <i class="bi bi-person-exclamation fs-5"></i>
            <div class="flex-grow-1"><strong>{{ $pending }}</strong> registration{{ $pending > 1 ? 's' : '' }} waiting for your approval</div>
            <i class="bi bi-chevron-right"></i>
        </a>
    @endif

    <div class="quick-actions mb-3">
        @can('conduct_inspections')
            <a href="{{ route('schools.index') }}" class="cta-card">
                <span class="cta-icon"><i class="bi bi-plus-lg"></i></span>
                <span class="flex-grow-1"><span class="d-block fw-bold">Start new inspection</span><span class="text-xs" style="opacity:.85">Search school by UDISE code</span></span>
                <i class="bi bi-arrow-right fs-5"></i>
            </a>
        @endcan
        <div class="summary-strip">
            <div><div class="v">{{ $stats['today'] }}</div><div class="l">Today</div></div>
            <div><div class="v">{{ $stats['month'] }}</div><div class="l">This month</div></div>
            <a href="{{ route('inspections.mine', ['status' => 'draft']) }}" class="text-decoration-none"><div class="v text-warning">{{ $stats['my_drafts'] }}</div><div class="l">Drafts</div></a>
        </div>
    </div>

    @if ($drafts->isNotEmpty())
        <div class="section-title">Continue draft</div>
        <div class="row g-2 mb-3">
            @foreach ($drafts as $d)
                <div class="col-md-6 col-xl-4">
                    <a href="{{ route('inspections.step', [$d, 1]) }}" class="card h-100 text-decoration-none">
                        <div class="card-body py-2 px-3">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                                <div class="fw-semibold text-sm text-truncate text-body">{{ $d->school->name }}</div>
                                <span class="text-xs fw-bold text-primary">{{ $d->completion_pct }}%</span>
                            </div>
                            <div class="text-xs text-secondary">UDISE {{ $d->school->udise_code }} · {{ $d->inspection_date->format('d M') }}</div>
                            <div class="progress-thin progress mt-2"><div class="progress-bar" style="width: {{ $d->completion_pct }}%"></div></div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-center">
        <div class="section-title mb-2">Recent submissions</div>
        <a href="{{ route('inspections.mine') }}" class="text-xs mb-2">View all</a>
    </div>
    <div class="card">
        <div class="list-card">
            @forelse ($recent as $r)
                <a href="{{ route('inspections.show', $r) }}" class="list-item text-decoration-none py-2">
                    <i class="bi bi-check-circle-fill text-success"></i>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-sm text-truncate">{{ $r->school->name }}</div>
                        <div class="text-xs text-secondary">{{ $r->inspection_no }} · {{ $r->submitted_at?->format('d M, h:i A') }}</div>
                    </div>
                    <i class="bi bi-chevron-right text-secondary"></i>
                </a>
            @empty
                <div class="text-center text-secondary text-sm py-4">No submitted inspections yet.</div>
            @endforelse
        </div>
    </div>
</x-layouts.app>
