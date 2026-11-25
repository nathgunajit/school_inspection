<x-layouts.app title="Dashboard">
    @php
        $hour = now()->hour;
        $greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $first = \Illuminate\Support\Str::of($user->name)->before(' ');
        $mineUrl = fn ($q = []) => $user->can('conduct_inspections') ? route('inspections.mine', $q) : null;
        $area = match ($user->level->value) {
            'cluster' => 'cluster', 'block' => 'block', 'district' => 'district', default => 'state',
        };
        $cards = [
            ['Total schools in your '.$area, $stats['schools'], 'bi-building', 'primary',
                $user->can('conduct_inspections') ? route('schools.index') : null, $stats['schools_inspected_month'].' inspected this month'],
            ['Inspected today', $stats['today'], 'bi-calendar-check', 'primary', $mineUrl()],
            ['This month', $stats['month'], 'bi-bar-chart-line', 'success', $mineUrl()],
            ['Drafts in progress', $stats['my_drafts'], 'bi-pencil-square', 'warning', $mineUrl(['status' => 'draft'])],
            $pending
                ? ['Pending approvals', $pending, 'bi-person-check', 'danger', route('admin.approvals.index')]
                : ['Total submitted', $stats['total'], 'bi-clipboard2-check', 'info', $mineUrl()],
        ];
    @endphp

    {{-- Welcome banner --}}
    <section class="dash-hero">
        <div class="dash-hero-pattern" aria-hidden="true"><i class="bi bi-mortarboard"></i><i class="bi bi-book"></i><i class="bi bi-building"></i></div>
        <div class="dash-hero-body">
            <div class="min-w-0">
                <div class="dash-date"><i class="bi bi-calendar3 me-1"></i>{{ now()->format('l, d F Y') }}</div>
                <h2 class="dash-title">{{ $greet }}, {{ $first }}</h2>
                <div class="dash-sub"><i class="bi bi-geo-alt me-1"></i>{{ $jurisdiction?->label() ?: $user->level->label() }}</div>
            </div>
            @can('conduct_inspections')
                <a href="{{ route('schools.index') }}" class="dash-hero-btn"><i class="bi bi-plus-lg"></i>Start inspection</a>
            @endcan
        </div>
    </section>

    {{-- Key figures --}}
    <div class="row g-3 mb-4">
        @foreach ($cards as $card)
            @php [$label, $value, $icon, $tone, $href] = $card; $hint = $card[5] ?? null; @endphp
            <div class="{{ $loop->first ? 'col-12 col-md-4' : 'col-6 col-md-4' }} col-xl">
                <a @if ($href) href="{{ $href }}" @endif class="kpi-card kpi-{{ $tone }} {{ $href ? '' : 'is-static' }}">
                    <span class="kpi-icon"><i class="bi {{ $icon }}"></i></span>
                    <span class="kpi-body"><span class="kpi-value">{{ $value }}</span><span class="kpi-label">{{ $label }}</span>@if ($hint)<span class="kpi-hint">{{ $hint }}</span>@endif</span>
                </a>
            </div>
        @endforeach
    </div>

    {{-- V14 item 1: latest instructions / notifications / orders --}}
    @if ($notices->isNotEmpty())
        <div class="dash-panel mb-4">
            <div class="dash-panel-head">
                <h3><i class="bi bi-megaphone"></i>Notices &amp; orders</h3>
                <a href="{{ route('notices.index') }}">View all <i class="bi bi-arrow-right"></i></a>
            </div>
            @foreach ($notices as $n)
                @php $new = ! $n->reads->first()?->read_at; @endphp
                <a href="{{ route('notices.show', $n) }}" class="dash-row">
                    <span class="notice-icon is-{{ $n->type }}"><i class="bi {{ $n->typeIcon() }}"></i></span>
                    <span class="flex-grow-1 min-w-0">
                        <span class="d-block fw-semibold text-sm text-truncate text-body">{{ $n->title }}</span>
                        <span class="d-block text-xs text-secondary text-truncate">{{ $n->typeLabel() }}@if ($n->ref_no) · No. {{ $n->ref_no }}@endif · {{ $n->creator->name }}</span>
                    </span>
                    @if ($new)<span class="badge text-bg-warning">New</span>@endif
                    <span class="text-xs text-secondary text-nowrap">{{ \Carbon\Carbon::parse($n->notice_date)->format('d M') }}</span>
                </a>
            @endforeach
        </div>
    @endif

    {{-- Today's published plan (New Module 2) --}}
    @if ($todayPlan)
        <div class="dash-panel mb-4">
            <div class="dash-panel-head">
                <h3><i class="bi bi-signpost-split"></i>Today's plan · {{ $todayPlan->items->where('status', 'inspected')->count() }} of {{ $todayPlan->items->count() }} done</h3>
                <a href="{{ route('schedules.show', $todayPlan) }}">Open plan <i class="bi bi-arrow-right"></i></a>
            </div>
            @foreach ($todayPlan->items as $i)
                <a href="{{ $i->status === 'planned' ? route('schools.show', \App\Models\School::routeKeyFor($i->school_id)) : route('schedules.show', $todayPlan) }}" class="dash-row">
                    <span class="plan-no {{ $i->status === 'inspected' ? 'is-done' : ($i->status === 'skipped' ? 'is-skipped' : '') }}">{{ $i->sequence }}</span>
                    <span class="flex-grow-1 min-w-0">
                        <span class="d-block fw-semibold text-sm text-truncate text-body">{{ $i->school->name }}</span>
                        <span class="d-block text-xs text-secondary">arrive {{ $i->planned_arrival }} · {{ ucfirst($i->status) }}</span>
                    </span>
                    <i class="bi bi-chevron-right text-secondary"></i>
                </a>
            @endforeach
        </div>
    @endif

    <div class="row g-4">
        {{-- Recent submissions --}}
        <div class="col-lg-7">
            <div class="dash-panel h-100">
                <div class="dash-panel-head">
                    <h3><i class="bi bi-clock-history"></i>Recent submissions</h3>
                    <a href="{{ route('inspections.mine') }}">View all <i class="bi bi-arrow-right"></i></a>
                </div>
                @forelse ($recent as $r)
                    <a href="{{ route('inspections.show', $r) }}" class="dash-row">
                        <span class="dash-row-icon bg-success-subtle text-success"><i class="bi bi-check2"></i></span>
                        <span class="flex-grow-1 min-w-0">
                            <span class="d-block fw-semibold text-sm text-truncate text-body">{{ $r->school->name }}</span>
                            <span class="d-block text-xs text-secondary text-truncate">{{ $r->inspection_no }}</span>
                        </span>
                        <span class="text-xs text-secondary text-nowrap">{{ $r->submitted_at?->format('d M') }}</span>
                    </a>
                @empty
                    <div class="dash-empty"><i class="bi bi-inbox"></i>No submitted inspections yet.</div>
                @endforelse
            </div>
        </div>

        {{-- Drafts --}}
        <div class="col-lg-5">
            <div class="dash-panel h-100">
                <div class="dash-panel-head">
                    <h3><i class="bi bi-pencil-square"></i>Continue where you left off</h3>
                </div>
                @forelse ($drafts as $d)
                    <a href="{{ route('inspections.step', [$d, 1]) }}" class="dash-row">
                        <span class="dash-ring" style="--p: {{ (int) $d->completion_pct }}"><span>{{ (int) $d->completion_pct }}%</span></span>
                        <span class="flex-grow-1 min-w-0">
                            <span class="d-block fw-semibold text-sm text-truncate text-body">{{ $d->school->name }}</span>
                            <span class="d-block text-xs text-secondary">UDISE {{ $d->school->udise_code }} · {{ $d->inspection_date->format('d M') }}</span>
                        </span>
                        <i class="bi bi-chevron-right text-secondary"></i>
                    </a>
                @empty
                    <div class="dash-empty"><i class="bi bi-check2-all"></i>No drafts pending. You're all caught up.</div>
                @endforelse
            </div>
        </div>
    </div>
</x-layouts.app>
