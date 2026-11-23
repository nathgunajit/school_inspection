{{-- 23-11-2026: My Schools, every school in the officer's own jurisdiction (SchoolController::mine) --}}
@php
    $keep = request()->except('inspected', 'page');
    $pct = fn ($n) => $stats['total'] ? round($n * 100 / $stats['total']).' % of '.number_format($stats['total']) : null;
@endphp
<x-layouts.app title="My Schools">
    <x-slot:actions>
        @can('export_excel')
            <a href="{{ route('schools.mine.export', request()->query()) }}" class="btn btn-soft-primary d-none d-sm-inline-flex" data-no-loader><i class="bi bi-file-earmark-excel"></i>Download Excel</a>
        @endcan
    </x-slot:actions>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <span class="badge-soft badge-soft-primary my-scope"><i class="bi bi-geo-alt"></i>{{ $scope }}</span>
        <span class="text-xs text-secondary">Schools you are responsible for. Not inspected schools are listed first.</span>
    </div>

    <div class="row g-2 g-md-3 mb-3">
        <div class="col-6 col-md-3"><x-stat-card wrap label="My schools" :value="number_format($stats['total'])" icon="bi-buildings" tone="primary" hint="in your area" :href="route('schools.mine', $keep)" /></div>
        <div class="col-6 col-md-3"><x-stat-card wrap label="Inspected" :value="number_format($stats['inspected'])" icon="bi-clipboard-check" tone="success" :hint="$pct($stats['inspected'])" :href="route('schools.mine', ['inspected' => 'yes'] + $keep)" /></div>
        <div class="col-6 col-md-3"><x-stat-card wrap label="Not inspected yet" :value="number_format($stats['not_inspected'])" icon="bi-hourglass-split" tone="warning" :hint="$pct($stats['not_inspected'])" :href="route('schools.mine', ['inspected' => 'no'] + $keep)" /></div>
        <div class="col-6 col-md-3"><x-stat-card wrap label="Inspected this month" :value="number_format($stats['this_month'])" icon="bi-calendar-check" tone="info" :hint="now()->format('F Y')" /></div>
    </div>

    <form method="GET" class="filter-bar mb-3" id="my-schools-filter">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md"><label class="form-label text-xs mb-1" for="ms-q">School</label>
                <div class="input-group"><input id="ms-q" name="q" value="{{ $q }}" class="form-control" placeholder="Name or UDISE Code" maxlength="100" type="search">
                    <button class="btn btn-primary" aria-label="Search"><i class="bi bi-search"></i></button></div></div>
            @if ($districts->isNotEmpty())
                <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="ms-d">District</label>
                    <select id="ms-d" name="district_id" class="form-select" data-autosubmit data-clears="block_id,cluster_id"><option value="">All districts</option>
                        @foreach ($districts as $d)<option value="{{ $d->id }}" @selected(request('district_id') == $d->id)>{{ $d->label }}</option>@endforeach</select></div>
            @endif
            @if ($blocks->isNotEmpty())
                <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="ms-b">Block</label>
                    <select id="ms-b" name="block_id" class="form-select" data-autosubmit data-clears="cluster_id"><option value="">All blocks</option>
                        @foreach ($blocks as $b)<option value="{{ $b->id }}" @selected(request('block_id') == $b->id)>{{ $b->label }}</option>@endforeach</select></div>
            @endif
            @if ($clusters->isNotEmpty())
                <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="ms-c">Cluster</label>
                    <select id="ms-c" name="cluster_id" class="form-select" data-autosubmit><option value="">All clusters</option>
                        @foreach ($clusters as $c)<option value="{{ $c->id }}" @selected(request('cluster_id') == $c->id)>{{ $c->label }}</option>@endforeach</select></div>
            @endif
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="ms-i">Inspection</label>
                <select id="ms-i" name="inspected" class="form-select" data-autosubmit>
                    <option value="">All schools</option>
                    <option value="no" @selected(request('inspected') === 'no')>Not inspected yet</option>
                    <option value="yes" @selected(request('inspected') === 'yes')>Inspected</option>
                </select></div>
            @if (request()->hasAny(['q', 'district_id', 'block_id', 'cluster_id', 'inspected']))
                <div class="col-auto"><a href="{{ route('schools.mine') }}" class="btn btn-light"><i class="bi bi-x-lg"></i>Reset</a></div>
            @endif
        </div>
    </form>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center gap-2">
            <span><i class="bi bi-buildings me-2 text-primary"></i>School list</span>
            <span class="text-xs text-secondary">{{ number_format($schools->total()) }} school{{ $schools->total() === 1 ? '' : 's' }}
                @if ($schools->lastPage() > 1) · showing {{ $schools->firstItem() }}–{{ $schools->lastItem() }} @endif</span>
        </div>
        <div class="table-responsive d-none d-md-block">
            <table class="table align-middle mb-0 my-schools-table">
                <thead><tr><th>School</th><th>Block · Cluster</th><th>Category</th><th>Inspections</th><th>Last inspected</th><th class="text-end"></th></tr></thead>
                <tbody>
                @forelse ($schools as $s)
                    <tr class="{{ $s->isActive() ? '' : 'text-secondary' }}">
                        <td><a href="{{ route('schools.show', $s) }}" class="fw-semibold text-decoration-none">{{ $s->name }}</a>
                            <div class="text-xs text-secondary">{{ $s->udise_code }}@unless ($s->isActive()) · <span class="text-danger">{{ ucfirst($s->status) }}</span>@endunless</div></td>
                        <td class="text-sm">{{ $s->block?->label }}<div class="text-xs text-secondary">{{ $s->cluster?->label }}</div></td>
                        <td class="text-sm">{{ $s->category?->label ?? '—' }}<div class="text-xs text-secondary">{{ $s->management?->label }}</div></td>
                        <td>@if ($s->visits)<span class="school-tag">{{ $s->visits }} time{{ $s->visits > 1 ? 's' : '' }}</span>@else<span class="school-tag todo">Not inspected yet</span>@endif</td>
                        <td class="text-sm text-nowrap">{{ $s->last_inspected_on ? \Illuminate\Support\Carbon::parse($s->last_inspected_on)->format('d M Y') : '—' }}</td>
                        <td class="text-end"><a href="{{ route('schools.show', $s) }}" class="btn btn-sm btn-soft-primary">Open<i class="bi bi-chevron-right"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty-state icon="bi-buildings" title="No schools match" text="No schools in your area match these filters." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="list-card d-md-none">
            @forelse ($schools as $s)
                <a href="{{ route('schools.show', $s) }}" class="list-item text-decoration-none">
                    <span class="school-badge"><i class="bi bi-building"></i></span>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate">{{ $s->name }}</div>
                        <div class="text-xs text-secondary text-truncate">{{ $s->udise_code }} · {{ $s->cluster?->label }}</div>
                        <div class="school-tags">@if ($s->visits)<span class="school-tag">Inspected {{ $s->visits }} time{{ $s->visits > 1 ? 's' : '' }} · {{ \Illuminate\Support\Carbon::parse($s->last_inspected_on)->format('d M Y') }}</span>@else<span class="school-tag todo">Not inspected yet</span>@endif</div>
                    </div>
                    <i class="bi bi-chevron-right text-secondary"></i>
                </a>
            @empty
                <x-empty-state icon="bi-buildings" title="No schools match" text="No schools in your area match these filters." />
            @endforelse
        </div>
    </div>
    <div class="mt-3">{{ $schools->links() }}</div>

    @push('scripts')
    <script>
        // a new district / block clears the lower selects before the form reloads
        document.querySelectorAll('#my-schools-filter [data-clears]').forEach((el) => el.addEventListener('change', () => {
            el.dataset.clears.split(',').forEach((n) => { const s = el.form.elements[n]; if (s) s.value = ''; });
        }, true));
    </script>
    @endpush
</x-layouts.app>
