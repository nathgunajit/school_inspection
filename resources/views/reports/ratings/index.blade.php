{{-- V6 Module 10: rating report (jurisdiction-scoped). Bars are plain CSS; every bar has its number beside it. --}}
@php
    $s = $summary;
    $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.');
    $maxBand = max(1, ...array_column($s['bands'], 'count'));
    $levelLabel = ['district' => 'District', 'block' => 'Block', 'cluster' => 'Cluster'][$areaLevel] ?? null;
    $drill = ['district' => 'district_id', 'block' => 'block_id', 'cluster' => 'cluster_id'][$areaLevel] ?? null;
    $keep = request()->except('page');
@endphp
<x-layouts.app title="Rating Report">
    <x-slot:actions>
        @can('export_excel')<a href="{{ route('reports.ratings.export', $keep) }}" class="btn btn-soft-primary d-none d-sm-inline-flex"><i class="bi bi-file-earmark-excel"></i>Excel</a>@endcan
        <a href="{{ route('reports.ratings.pdf', $keep) }}" target="_blank" rel="noopener" class="btn btn-soft-primary d-none d-sm-inline-flex"><i class="bi bi-file-earmark-pdf"></i>PDF</a>
    </x-slot:actions>

    <form method="GET" class="filter-bar mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="r-from">From</label><input type="date" id="r-from" name="from" value="{{ $filters['from'] }}" class="form-control"></div>
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="r-to">To</label><input type="date" id="r-to" name="to" value="{{ $filters['to'] }}" class="form-control"></div>
            @if ($districts->isNotEmpty())
                <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="r-d">District</label>
                    <select id="r-d" name="district_id" class="form-select" data-autosubmit onchange="this.form.block_id && (this.form.block_id.value=''); this.form.cluster_id && (this.form.cluster_id.value='')"><option value="">All</option>
                        @foreach ($districts as $d)<option value="{{ $d->id }}" @selected($filters['district_id'] === $d->id)>{{ $d->name }}</option>@endforeach</select></div>
            @endif
            @if ($blocks->isNotEmpty())
                <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="r-b">Block</label>
                    <select id="r-b" name="block_id" class="form-select" data-autosubmit onchange="this.form.cluster_id && (this.form.cluster_id.value='')"><option value="">All</option>
                        @foreach ($blocks as $b)<option value="{{ $b->id }}" @selected($filters['block_id'] === $b->id)>{{ $b->name }}</option>@endforeach</select></div>
            @endif
            @if ($clusters->isNotEmpty())
                <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="r-c">Cluster</label>
                    <select id="r-c" name="cluster_id" class="form-select" data-autosubmit><option value="">All</option>
                        @foreach ($clusters as $c)<option value="{{ $c->id }}" @selected($filters['cluster_id'] === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
            @endif
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="r-band">Rating band</label>
                <select id="r-band" name="band" class="form-select" data-autosubmit><option value="">All bands</option>
                    @foreach ($bandList as $b)<option value="{{ $b['label'] }}" @selected($filters['band'] === $b['label'])>{{ $b['label'] }}</option>@endforeach</select></div>
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="r-cat">School category</label>
                <select id="r-cat" name="category_id" class="form-select" data-autosubmit><option value="">All</option>
                    @foreach ($categories as $c)<option value="{{ $c->id }}" @selected($filters['category_id'] === $c->id)>{{ $c->label }}</option>@endforeach</select></div>
            @if ($versions->count() > 1)
                <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="r-v">Scheme version</label>
                    <select id="r-v" name="version" class="form-select" data-autosubmit><option value="">All versions</option>
                        @foreach ($versions as $v)<option value="{{ $v }}" @selected($filters['version'] === $v)>Version {{ $v }}</option>@endforeach</select></div>
            @endif
            <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
            <div class="col-6 col-md-1 d-grid"><button class="btn btn-primary" aria-label="Apply filters"><i class="bi bi-funnel"></i><span class="d-none d-xl-inline">Go</span></button></div>
        </div>
    </form>

    {{-- Modification V8 item 5: clean tiles, the label stays inside the box --}}
    <div class="row g-2 g-md-3 mb-3 rating-stats">
        <div class="col-6 col-lg-3"><x-stat-card wrap label="Rated inspections" :value="number_format($s['rated'])" icon="bi-clipboard-data" tone="primary" hint="submitted and rated" /></div>
        <div class="col-6 col-lg-3"><x-stat-card wrap label="Schools rated" :value="number_format($s['schools'])" icon="bi-building-check" tone="accent" hint="different schools" /></div>
        <div class="col-6 col-lg-3">
            <div class="card stat-card stat-card-wrap tone-edge-success h-100"><div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon tone-success"><i class="bi bi-star-half"></i></div>
                <div class="min-w-0"><div class="stat-value">{{ $s['avg'] === null ? '—' : $fmt($s['avg']).' %' }}</div>
                    <div class="stat-label stat-label-wrap">Average score</div>
                    @if ($s['avg_band']['band'])<span class="rating-pill rating-{{ $s['avg_band']['color'] }} mt-1">{{ $s['avg_band']['band'] }}</span>@endif</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3"><x-stat-card wrap label="Lowest – highest" :value="$s['rated'] ? $fmt($s['low']).' – '.$fmt($s['high']).' %' : '—'" icon="bi-arrows-expand" tone="info" hint="score range" /></div>
    </div>

    @if ($s['rated'] === 0)
        <div class="card"><x-empty-state icon="bi-star" title="No rated inspections" text="No submitted inspection in your area has a rating for these dates and filters. Widen the dates or clear a filter." /></div>
    @else
        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-6">
                {{-- Modification V8 item 5: donut of the band shares with the average in the middle, and a legend that filters --}}
                @php
                    $hex = ['green' => '#1e9b4b', 'blue' => '#1565c0', 'yellow' => '#d4a500', 'orange' => '#f39223', 'red' => '#d93025', 'none' => '#94a3b8'];
                    $stops = []; $at = 0.0;
                    foreach ($s['bands'] as $b) {
                        if (! $b['count']) continue;
                        $to = $at + $b['count'] * 100 / $s['rated'];
                        $c = $hex[$b['color'] ?? 'none'] ?? $hex['none'];
                        $stops[] = $c.' '.round($at, 2).'% '.round($to, 2).'%';
                        $at = $to;
                    }
                    $donut = $stops ? 'conic-gradient('.implode(', ', $stops).')' : 'conic-gradient(#e5e7eb 0 100%)';
                @endphp
                <div class="card h-100 report-card">
                    <div class="card-header"><span class="rc-icon"><i class="bi bi-pie-chart"></i></span>Rating bands<span class="rc-sub">{{ number_format($s['rated']) }} rated inspection(s)</span></div>
                    <div class="card-body">
                        <div class="band-overview">
                            <div class="report-donut" style="--donut: {{ $donut }}" role="img" aria-label="Share of inspections by rating band">
                                <div class="rd-hole"><b>{{ $s['avg'] === null ? '—' : $fmt($s['avg']).'%' }}</b><span>average</span>
                                    @if ($s['avg_band']['band'])<em class="rating-pill rating-{{ $s['avg_band']['color'] }}">{{ $s['avg_band']['band'] }}</em>@endif</div>
                            </div>
                            <div class="band-legend">
                                @foreach ($s['bands'] as $b)
                                    @php $share = $s['rated'] ? round($b['count'] * 100 / $s['rated']) : 0; @endphp
                                    <a href="{{ route('reports.ratings', ['band' => $b['label']] + request()->except('band', 'page')) }}"
                                       class="band-item {{ $filters['band'] === $b['label'] ? 'is-active' : '' }} {{ $b['count'] ? '' : 'is-empty' }}"
                                       title="{{ $b['label'] }}: {{ $b['count'] }} inspection(s), {{ $share }} %">
                                        <span class="rating-swatch rating-{{ $b['color'] ?? 'none' }}"></span>
                                        <span class="bi-name">{{ $b['label'] }}@if ($b['min'] !== null)<small>{{ $b['min'] }} %+</small>@endif</span>
                                        <span class="bi-count"><b>{{ number_format($b['count']) }}</b><small>{{ $share }} %</small></span>
                                        <span class="bi-bar"><span class="rating-{{ $b['color'] ?? 'none' }}" style="width: {{ round($b['count'] * 100 / $maxBand) }}%"></span></span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                @php $stepIcon = collect(\App\Questionnaire\QuestionnaireDefinition::steps())->pluck('icon', 'key'); @endphp
                <div class="card h-100 report-card">
                    <div class="card-header"><span class="rc-icon"><i class="bi bi-layout-text-sidebar"></i></span>Average by section<span class="rc-sub">coloured by rating band</span></div>
                    <div class="card-body section-scores">
                        @foreach ($s['sections'] as $sec)
                            <div class="sec-row {{ $sec['avg'] === null ? 'is-na' : '' }}" title="{{ $sec['title'] }}: {{ $sec['avg'] === null ? 'not applicable' : $fmt($sec['avg']).' % over '.$sec['count'].' inspection(s)' }}">
                                <span class="sec-icon rating-tint-{{ $sec['color'] ?? 'none' }}"><i class="bi {{ $stepIcon[$sec['key']] ?? 'bi-ui-checks' }}"></i></span>
                                <div class="sec-main">
                                    <div class="sec-top"><span class="sec-title">{{ $sec['title'] }}</span>
                                        @if ($sec['avg'] === null)<span class="sec-na">N/A</span>
                                        @else<span class="sec-score"><b>{{ $fmt($sec['avg']) }} %</b><span class="rating-pill rating-{{ $sec['color'] ?? 'none' }}">{{ $sec['band'] }}</span></span>@endif
                                    </div>
                                    <div class="rating-bar"><span class="rating-{{ $sec['color'] ?? 'none' }}" style="width: {{ $sec['avg'] ?? 0 }}%"></span></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        @if ($areas)
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-diagram-3 me-2 text-primary"></i>{{ $levelLabel }}-wise rating</div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>{{ $levelLabel }}</th><th class="text-end">Rated</th><th class="text-end d-none d-sm-table-cell">Schools</th><th>Average</th>
                            @foreach ($bandList as $b)<th class="text-end d-none d-md-table-cell" title="{{ $b['label'] }}"><span class="rating-swatch rating-{{ $b['color'] }}"></span><span class="visually-hidden">{{ $b['label'] }}</span></th>@endforeach</tr></thead>
                        <tbody>
                        @foreach ($areas as $a)
                            <tr>
                                <td><a href="{{ route('reports.ratings', [$drill => $a['id']] + request()->except($drill, 'page')) }}">{{ $a['name'] }}</a></td>
                                <td class="text-end">{{ number_format($a['rated']) }}</td>
                                <td class="text-end d-none d-sm-table-cell">{{ number_format($a['schools']) }}</td>
                                <td><span class="rating-pill rating-{{ $a['color'] }}">{{ $fmt($a['avg']) }}% · {{ $a['band'] }}</span></td>
                                @foreach ($bandList as $b)<td class="text-end d-none d-md-table-cell text-sm">{{ $a['bands'][$b['label']] ?? '·' }}</td>@endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="row g-3 mb-3">
            @foreach (['Best rated schools' => [$best, 'bi-trophy', 'text-success'], 'Lowest rated schools' => [$lowest, 'bi-exclamation-triangle', 'text-danger']] as $title => [$list, $icon, $tone])
                <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header"><i class="bi {{ $icon }} me-2 {{ $tone }}"></i>{{ $title }}</div>
                        <div class="list-card">
                            @foreach ($list as $r)
                                <a href="{{ route('inspections.show', $r['inspection']) }}" class="list-item text-decoration-none">
                                    <div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate">{{ $r['school']->name }}</div>
                                        <div class="text-xs text-secondary text-truncate">{{ $r['school']->udise_code }} · {{ $r['inspection']->cluster?->name }} · {{ $r['inspection']->inspection_date->format('d M Y') }}</div></div>
                                    <span class="rating-pill rating-{{ $r['color'] }}">{{ $fmt($r['score']) }}% · {{ $r['band'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card">
            <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <span><i class="bi bi-building me-2 text-primary"></i>Schools <span class="text-xs text-secondary">(latest rating in the period, against the rating before it)</span></span>
                <form method="GET" class="d-flex align-items-center gap-2">
                    @foreach (request()->except('sort', 'page') as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <label for="r-sort" class="text-xs text-secondary text-nowrap">Sort</label>
                    <select id="r-sort" name="sort" class="form-select form-select-sm" data-autosubmit>
                        @foreach ($sorts as $k => $label)<option value="{{ $k }}" @selected($filters['sort'] === $k)>{{ $label }}</option>@endforeach</select>
                </form>
            </div>
            <div class="table-responsive d-none d-md-block">
                <table class="table align-middle mb-0">
                    <thead><tr><th>School</th><th>Area</th><th>Inspected</th><th>Rating</th><th>Previous</th><th class="text-end">Change</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td><div class="fw-semibold">{{ $r['school']->name }}</div><div class="text-xs text-secondary">{{ $r['school']->udise_code }}@if ($r['school']->category) · {{ $r['school']->category->label }}@endif</div></td>
                            <td class="text-sm">{{ $r['inspection']->cluster?->name }}<div class="text-xs text-secondary">{{ $r['inspection']->block?->name }} · {{ $r['inspection']->district?->name }}</div></td>
                            <td class="text-sm text-nowrap">{{ $r['inspection']->inspection_date->format('d M Y') }}<div class="text-xs text-secondary">{{ $r['inspection']->officer?->name }}</div></td>
                            <td><span class="rating-pill rating-{{ $r['color'] }}">{{ $fmt($r['score']) }}% · {{ $r['band'] }}</span></td>
                            <td class="text-sm">@if ($r['previous'])<span class="rating-pill rating-{{ $r['previous']['color'] }}">{{ $fmt($r['previous']['score']) }}%</span><div class="text-xs text-secondary">{{ \Illuminate\Support\Carbon::parse($r['previous']['date'])->format('d M Y') }}</div>@else<span class="text-secondary text-xs">First rating</span>@endif</td>
                            <td class="text-end text-nowrap">@if ($r['change'] !== null)<span class="{{ $r['change'] > 0 ? 'text-success' : ($r['change'] < 0 ? 'text-danger' : 'text-secondary') }} fw-semibold"><i class="bi {{ $r['change'] > 0 ? 'bi-arrow-up' : ($r['change'] < 0 ? 'bi-arrow-down' : 'bi-dash') }}"></i>{{ $fmt(abs($r['change'])) }} pts</span>@else — @endif</td>
                            <td class="text-end"><a href="{{ route('inspections.show', $r['inspection']) }}" class="btn btn-sm btn-soft-primary">Report</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="list-card d-md-none">
                @foreach ($rows as $r)
                    <a href="{{ route('inspections.show', $r['inspection']) }}" class="list-item text-decoration-none">
                        <div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate">{{ $r['school']->name }}</div>
                            <div class="text-xs text-secondary text-truncate">{{ $r['inspection']->inspection_date->format('d M Y') }} · {{ $r['inspection']->cluster?->name }}
                                @if ($r['change'] !== null) · <span class="{{ $r['change'] >= 0 ? 'text-success' : 'text-danger' }}">{{ $r['change'] >= 0 ? '+' : '−' }}{{ $fmt(abs($r['change'])) }} pts</span>@endif</div></div>
                        <span class="rating-pill rating-{{ $r['color'] }}">{{ $fmt($r['score']) }}%</span>
                    </a>
                @endforeach
            </div>
        </div>
        <div class="mt-3">{{ $rows->links() }}</div>
    @endif
</x-layouts.app>
