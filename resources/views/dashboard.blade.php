<x-layouts.app title="Dashboard">
    <div class="hero mb-4">
        <div class="row align-items-center g-3 position-relative" style="z-index:1">
            <div class="col-md">
                <div class="text-white-75 text-sm mb-1">{{ now()->format('l, d F Y') }}</div>
                <h2 class="h3 fw-bold mb-1">Hello, {{ \Illuminate\Support\Str::of($user->name)->before(' ') }} 👋</h2>
                <div class="text-white-75">{{ $user->designation?->label ?? $user->level->label() }} · {{ $jurisdiction?->label() ?: 'No jurisdiction' }}</div>
            </div>
            @can('conduct_inspections')
                <div class="col-md-auto">
                    <a href="{{ route('schools.index') }}" class="btn btn-light btn-lg fw-semibold text-primary w-100"><i class="bi bi-plus-circle"></i>Start new inspection</a>
                </div>
            @endcan
        </div>
    </div>

    @if ($pending)
        <a href="{{ route('admin.approvals.index') }}" class="alert alert-warning border-0 d-flex align-items-center gap-3 text-decoration-none mb-4">
            <i class="bi bi-person-exclamation fs-4"></i>
            <div class="flex-grow-1"><strong>{{ $pending }} registration{{ $pending > 1 ? 's' : '' }}</strong> waiting for your approval</div>
            <i class="bi bi-chevron-right"></i>
        </a>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><x-stat-card label="Schools in jurisdiction" :value="number_format($stats['schools'])" icon="bi-building" tone="primary" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Schools inspected this month" :value="number_format($stats['inspected'])" icon="bi-check2-circle" tone="accent" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Inspections submitted this month" :value="number_format($stats['submitted'])" icon="bi-send-check" tone="success" :href="auth()->user()->can('view_reports') ? route('inspections.area') : null" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="My drafts in progress" :value="$stats['my_drafts']" icon="bi-pencil-square" tone="warning" :href="route('inspections.mine', ['status' => 'draft'])" /></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-pencil-square text-warning me-2"></i>Continue where you left off</span>
                </div>
                <div class="list-card">
                    @forelse ($drafts as $d)
                        <a href="{{ route('inspections.step', [$d, 1]) }}" class="list-item text-decoration-none">
                            <span class="stat-icon tone-warning" style="width:40px;height:40px;border-radius:10px;display:grid;place-items:center"><i class="bi bi-file-earmark-text"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold text-truncate">{{ $d->school->name }}</div>
                                <div class="text-xs text-secondary">UDISE {{ $d->school->udise_code }} · {{ $d->inspection_date->format('d M Y') }}</div>
                                <div class="progress-thin progress mt-2"><div class="progress-bar" style="width: {{ $d->completion_pct }}%"></div></div>
                            </div>
                            <span class="text-sm fw-semibold text-primary">{{ $d->completion_pct }}%</span>
                        </a>
                    @empty
                        <x-empty-state icon="bi-clipboard-plus" title="No drafts" text="Start an inspection by searching for a school by UDISE code." />
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history text-primary me-2"></i>Recently submitted</span>
                    <a href="{{ route('inspections.mine') }}" class="text-sm">View all</a>
                </div>
                <div class="list-card">
                    @forelse ($recent as $r)
                        <a href="{{ route('inspections.show', $r) }}" class="list-item text-decoration-none">
                            <span class="stat-icon tone-success" style="width:40px;height:40px;border-radius:10px;display:grid;place-items:center"><i class="bi bi-check2"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold text-truncate">{{ $r->school->name }}</div>
                                <div class="text-xs text-secondary">{{ $r->inspection_no }} · {{ $r->submitted_at?->format('d M Y, h:i A') }}</div>
                            </div>
                            <i class="bi bi-chevron-right text-secondary"></i>
                        </a>
                    @empty
                        <x-empty-state icon="bi-send" title="No submitted inspections yet" />
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
