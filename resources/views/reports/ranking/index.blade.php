{{-- 27-11-2026: District Ranking. Coverage % (Inspection coverage) and Rating % (State Admin) ranked separately, for one period. --}}
@php
    use App\Models\CoverageSetting;
    use App\Services\DistrictRankingService as R;
    $keep = collect(request()->query())->except(['page'])->all();
    $periodQuery = fn ($p) => array_merge($keep, ['type' => $p->type, 'ref' => $p->fromDate()] + ($p->type === 'custom' ? ['from' => $p->fromDate(), 'to' => $p->toDate()] : []));
    $periodArgs = ['type' => $period->type, 'ref' => $period->fromDate()] + ($period->type === 'custom' ? ['from' => $period->fromDate(), 'to' => $period->toDate()] : []);
    $medal = fn ($rank) => match ($rank) { 1 => 'is-gold', 2 => 'is-silver', 3 => 'is-bronze', default => '' };
@endphp
<x-layouts.app title="District Ranking">
    <x-slot:actions>
        @can('export_excel')<span id="rk-actions" class="d-contents" data-filter-region>
            <a href="{{ route('reports.ranking.export', $keep) }}" class="btn btn-soft-primary d-none d-sm-inline-flex" data-no-loader title="Download both rankings as Excel"><i class="bi bi-file-earmark-excel"></i>Excel</a>
            <a href="{{ route('reports.ranking.export-pdf', $keep) }}" class="btn btn-soft-danger d-none d-sm-inline-flex" data-no-loader title="Download both rankings as PDF"><i class="bi bi-file-earmark-pdf"></i>PDF</a>
        </span>@endcan
    </x-slot:actions>
    <x-page-tabs group="reports" />

    <div id="rk-top" data-filter-region>
    @can('export_excel')
        <div class="d-flex gap-2 mb-3 d-sm-none">
            <a href="{{ route('reports.ranking.export', $keep) }}" class="btn btn-sm btn-soft-primary flex-fill" data-no-loader><i class="bi bi-file-earmark-excel"></i>Excel</a>
            <a href="{{ route('reports.ranking.export-pdf', $keep) }}" class="btn btn-sm btn-soft-danger flex-fill" data-no-loader><i class="bi bi-file-earmark-pdf"></i>PDF</a>
        </div>
    @endcan

    {{-- ============ Period bar (as on the Coverage Report) ============ --}}
    <section class="cov-period">
        <div class="cov-period-main">
            <a href="{{ route('reports.ranking', $periodQuery($period->previous())) }}" class="cov-nav" aria-label="Previous period" title="Previous"><i class="bi bi-chevron-left"></i></a>
            <div class="cov-period-text">
                <span class="cov-eyebrow"><i class="bi bi-trophy"></i>{{ CoverageSetting::PERIODS[$period->type] }} district ranking · Whole state</span>
                <h2>{{ $period->label() }}</h2>
                <span class="cov-dates">{{ $period->from->format('d M Y') }} – {{ $period->to->format('d M Y') }} · inspection cycle {{ $cycle->from->format('d M') }} – {{ $cycle->to->format('d M Y') }}</span>
            </div>
            <a href="{{ route('reports.ranking', $periodQuery($period->next())) }}" class="cov-nav" aria-label="Next period" title="Next"><i class="bi bi-chevron-right"></i></a>
        </div>
        <div class="cov-period-side">
            <div class="cov-types" role="tablist" aria-label="Ranking period">
                @foreach (CoverageSetting::PERIODS as $t => $l)
                    @continue($t === 'custom')
                    <a href="{{ route('reports.ranking', array_merge(collect($keep)->except(['ref', 'from', 'to'])->all(), ['type' => $t])) }}" class="{{ $period->type === $t ? 'active' : '' }}" role="tab">{{ $l }}</a>
                @endforeach
                <button type="button" class="{{ $period->type === 'custom' ? 'active' : '' }}" data-bs-toggle="collapse" data-bs-target="#rk-custom" aria-expanded="{{ $period->type === 'custom' ? 'true' : 'false' }}">Custom</button>
            </div>
            <form method="GET" class="d-flex gap-2 align-items-center" data-filter data-loader-text="Ranking districts…">
                @foreach (collect($keep)->only(['type', 'ref', 'from', 'to']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                <label class="form-label text-xs mb-0 text-nowrap" for="rk-cat">School category</label>
                <select id="rk-cat" name="category_id" class="form-select form-select-sm" data-autosubmit><option value="">All</option>
                    @foreach ($categories as $c)<option value="{{ $c->id }}" @selected($filters['category_id'] === $c->id)>{{ $c->label }}</option>@endforeach</select>
            </form>
        </div>
        <form method="GET" id="rk-custom" data-filter data-loader-text="Ranking districts…" class="collapse cov-custom {{ $period->type === 'custom' ? 'show' : '' }}">
            @foreach (collect($keep)->except(['type', 'ref', 'from', 'to']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <input type="hidden" name="type" value="custom">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3"><label class="form-label text-xs mb-1" for="rk-from">From</label><input type="date" id="rk-from" name="from" value="{{ $period->type === 'custom' ? $period->fromDate() : '' }}" class="form-control form-control-sm" required></div>
                <div class="col-6 col-md-3"><label class="form-label text-xs mb-1" for="rk-to">To</label><input type="date" id="rk-to" name="to" value="{{ $period->type === 'custom' ? $period->toDate() : '' }}" class="form-control form-control-sm" required></div>
                <div class="col-12 col-md-auto"><x-filter-buttons label="Apply range" icon="bi-check2" :reset="false" /></div>
            </div>
        </form>
    </section>
    @include('reports.coverage.partials.how-counted', ['how' => $how])
    </div>

    <div id="rk-results" class="row g-3" data-filter-region data-filter-results data-loader-text="Ranking districts…">
        {{-- ============ Coverage ranking ============ --}}
        <div class="{{ $showRating ? 'col-lg-6' : 'col-12' }}">
            <section class="card h-100">
                <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-patch-check text-primary"></i><span>Coverage ranking</span>
                    <span class="ms-auto text-xs text-secondary">by Inspection coverage %</span></div>
                <div class="rv-table-wrap cov-table-wrap">
                    <table class="rv-table cov-table rank-table">
                        <thead><tr><th class="c">Rank</th><th>District</th><th class="c">Schools</th><th class="c">Inspected</th><th class="c">Inspection %</th><th class="c">Visit %</th></tr></thead>
                        <tbody>
                        @forelse ($coverage as $r)
                            <tr>
                                <td class="c" data-label="Rank">@if ($r['rank'])<span class="rank-badge {{ $medal($r['rank']) }}">{{ $r['rank'] }}</span>@else<span class="text-secondary">—</span>@endif</td>
                                <td class="rv-wide"><a href="{{ route('reports.coverage', $periodArgs + ['district_id' => $r['id'], 'category_id' => $filters['category_id']]) }}" class="cov-area">{{ $r['name'] }}<i class="bi bi-chevron-right"></i></a></td>
                                <td class="c" data-label="Schools">{{ number_format($r['schools']) }}</td>
                                <td class="c" data-label="Inspected"><b>{{ number_format($r['inspected']) }}</b></td>
                                <td class="c" data-label="Inspection %">
                                    <div class="cov-bar tone-{{ $r['tone'] }}"><i style="width: {{ min(100, $r['pct'] ?? 0) }}%"></i></div>
                                    <span class="cov-pct">{{ R::pct($r['pct']) }}</span>
                                </td>
                                <td class="c" data-label="Visit %">{{ R::pct($r['visit_pct']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-empty-state icon="bi-trophy" title="No districts" text="Load the Location Master and School Master first." /></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white text-xs text-secondary"><i class="bi bi-info-circle me-1"></i>Inspection coverage = schools with a submitted inspection in the inspection cycle ÷ active schools. Equal percentages share a rank; ties are listed by schools inspected. Districts with no schools have no rank.</div>
            </section>
        </div>

        {{-- ============ Rating ranking (State Admin, like the Rating Report) ============ --}}
        @if ($showRating)
        <div class="col-lg-6">
            <section class="card h-100">
                <div class="card-header d-flex align-items-center gap-2"><i class="bi bi-star-half text-warning"></i><span>Rating ranking</span>
                    <span class="ms-auto text-xs text-secondary">by average rating %</span></div>
                <div class="rv-table-wrap cov-table-wrap">
                    <table class="rv-table cov-table rank-table">
                        <thead><tr><th class="c">Rank</th><th>District</th><th class="c">Rated inspections</th><th class="c">Schools</th><th class="c">Average rating %</th></tr></thead>
                        <tbody>
                        @forelse ($rating as $r)
                            <tr>
                                <td class="c" data-label="Rank">@if ($r['rank'])<span class="rank-badge {{ $medal($r['rank']) }}">{{ $r['rank'] }}</span>@else<span class="text-secondary">—</span>@endif</td>
                                <td class="rv-wide"><a href="{{ route('reports.ratings', ['from' => $period->fromDate(), 'to' => $period->toDate(), 'district_id' => $r['id'], 'category_id' => $filters['category_id']]) }}" class="cov-area">{{ $r['name'] }}<i class="bi bi-chevron-right"></i></a></td>
                                <td class="c" data-label="Rated inspections">{{ number_format($r['rated']) }}</td>
                                <td class="c" data-label="Schools">{{ number_format($r['schools']) }}</td>
                                <td class="c" data-label="Average rating %">
                                    @if ($r['pct'] === null)
                                        <span class="text-secondary">Not rated</span>
                                    @else
                                        <div class="cov-bar"><i style="width: {{ min(100, $r['pct']) }}%; background: {{ $r['color'] ?? '#1565c0' }}"></i></div>
                                        <span class="cov-pct">{{ R::pct($r['pct']) }}</span>
                                        @if ($r['band'])<span class="rank-band" style="--rb: {{ $r['color'] ?? '#1565c0' }}">{{ $r['band'] }}</span>@endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-empty-state icon="bi-star" title="No districts" text="Load the Location Master first." /></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white text-xs text-secondary"><i class="bi bi-info-circle me-1"></i>Average of the rating % of the submitted, rated inspections dated in the period. Equal averages share a rank; ties are listed by rated inspections. Districts with no rated inspection are listed last as Not rated.</div>
            </section>
        </div>
        @else
        <div class="col-12"><div class="alert alert-light border text-sm mb-0"><i class="bi bi-info-circle me-1"></i>The Rating ranking is shown to the State Administrator only, like the Rating Report.</div></div>
        @endif
    </div>
</x-layouts.app>
