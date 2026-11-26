{{-- Inspection Coverage Report: Visit Coverage (verified visit in the period) and Inspection Coverage (submitted in the cycle) --}}
@php
    use App\Models\CoverageSetting;
    $s = $summary;
    $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
    $keep = collect(request()->query())->except(['page'])->all();
    $base = collect($keep)->except(['level'])->all();
    $periodQuery = fn ($p) => array_merge($keep, ['type' => $p->type, 'ref' => $p->fromDate()] + ($p->type === 'custom' ? ['from' => $p->fromDate(), 'to' => $p->toDate()] : []));
    $drill = function ($r) use ($base) {
        return match ($r['level']) {
            'district' => route('reports.coverage', array_merge($base, ['district_id' => $r['id'], 'block_id' => null, 'cluster_id' => null])),
            'block' => route('reports.coverage', array_merge($base, ['district_id' => $r['district_id'], 'block_id' => $r['id'], 'cluster_id' => null])),
            default => route('reports.coverage', array_merge($base, ['district_id' => $r['district_id'], 'block_id' => $r['block_id'], 'cluster_id' => $r['id']])),
        };
    };
    $ring = fn ($v, $tone) => '<span class="cov-ring tone-'.$tone.'" style="--p: '.(int) round($v ?? 0).'"><b>'.e($v === null ? '—' : (int) round($v)).'</b></span>';
@endphp
<x-layouts.app title="Inspection Coverage Report">
    <x-slot:actions>
        @can('configure_coverage')<a href="{{ route('reports.coverage.settings') }}" class="btn btn-light d-none d-sm-inline-flex"><i class="bi bi-sliders"></i>Configure</a>@endcan
        {{-- 23-11-2026 (V14): both downloads hold the view on screen (the selected tab, or the cluster's schools) --}}
        @can('export_excel')<span id="cov-actions" class="d-contents" data-filter-region>
            <a href="{{ route('reports.coverage.export', $keep + ['level' => $level]) }}" class="btn btn-soft-primary d-none d-sm-inline-flex" data-no-loader title="Download the {{ $period->typeLabel() }} {{ $viewLabel }} report as Excel"><i class="bi bi-file-earmark-excel"></i>Excel · {{ $viewLabel }}</a>
            <a href="{{ route('reports.coverage.export-pdf', $keep + ['level' => $level]) }}" class="btn btn-soft-danger d-none d-sm-inline-flex" data-no-loader title="Download the {{ $period->typeLabel() }} {{ $viewLabel }} report as PDF"><i class="bi bi-file-earmark-pdf"></i>PDF · {{ $viewLabel }}</a>
        </span>@endcan
    </x-slot:actions>
    <x-page-tabs group="reports" />

    <div id="cov-top" data-filter-region>
    @can('export_excel')
        <div class="d-flex gap-2 mb-3 d-sm-none">
            <a href="{{ route('reports.coverage.export', $keep + ['level' => $level]) }}" class="btn btn-sm btn-soft-primary flex-fill" data-no-loader><i class="bi bi-file-earmark-excel"></i>Excel · {{ $viewLabel }}</a>
            <a href="{{ route('reports.coverage.export-pdf', $keep + ['level' => $level]) }}" class="btn btn-sm btn-soft-danger flex-fill" data-no-loader><i class="bi bi-file-earmark-pdf"></i>PDF · {{ $viewLabel }}</a>
        </div>
    @endcan

    {{-- ============ Period bar ============ --}}
    <section class="cov-period">
        <div class="cov-period-main">
            <a href="{{ route('reports.coverage', $periodQuery($period->previous())) }}" class="cov-nav" aria-label="Previous period" title="Previous"><i class="bi bi-chevron-left"></i></a>
            <div class="cov-period-text">
                <span class="cov-eyebrow"><i class="bi bi-calendar-range"></i>{{ CoverageSetting::PERIODS[$period->type] }} coverage · {{ $areaTitle }}</span>
                <h2>{{ $period->label() }}</h2>
                <span class="cov-dates">{{ $period->from->format('d M Y') }} – {{ $period->to->format('d M Y') }} · {{ $period->days() }} day{{ $period->days() > 1 ? 's' : '' }}</span>
            </div>
            <a href="{{ route('reports.coverage', $periodQuery($period->next())) }}" class="cov-nav" aria-label="Next period" title="Next"><i class="bi bi-chevron-right"></i></a>
        </div>
        <div class="cov-period-side">
            <div class="cov-types" role="tablist" aria-label="Coverage period">
                @foreach (CoverageSetting::PERIODS as $t => $l)
                    @continue($t === 'custom')
                    <a href="{{ route('reports.coverage', array_merge(collect($keep)->except(['ref', 'from', 'to'])->all(), ['type' => $t])) }}" class="{{ $period->type === $t ? 'active' : '' }}" role="tab">{{ $l }}</a>
                @endforeach
                <button type="button" class="{{ $period->type === 'custom' ? 'active' : '' }}" data-bs-toggle="collapse" data-bs-target="#cov-custom" aria-expanded="{{ $period->type === 'custom' ? 'true' : 'false' }}">Custom</button>
            </div>
            <div class="cov-setting">
                <span><i class="bi bi-gear"></i>Configured: <b>{{ $setting->periodLabel() }}</b> · {{ $setting->district_id ? 'set for '.$setting->district?->label : 'state default' }}</span>
                <span><i class="bi bi-arrow-repeat"></i>Inspection cycle: <b>{{ $setting->cycleLabel() }}</b> ({{ $cycle->from->format('d M') }} – {{ $cycle->to->format('d M Y') }})</span>
            </div>
        </div>
        <form method="GET" id="cov-custom" data-filter data-loader-text="Calculating coverage…" class="collapse cov-custom {{ $period->type === 'custom' ? 'show' : '' }}">
            @foreach (collect($keep)->except(['type', 'ref', 'from', 'to']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <input type="hidden" name="type" value="custom">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3"><label class="form-label text-xs mb-1" for="cv-from">From</label><input type="date" id="cv-from" name="from" value="{{ $period->type === 'custom' ? $period->fromDate() : '' }}" class="form-control form-control-sm" required></div>
                <div class="col-6 col-md-3"><label class="form-label text-xs mb-1" for="cv-to">To</label><input type="date" id="cv-to" name="to" value="{{ $period->type === 'custom' ? $period->toDate() : '' }}" class="form-control form-control-sm" required></div>
                <div class="col-12 col-md-auto"><x-filter-buttons label="Apply range" icon="bi-check2" :reset="false" /></div>
            </div>
        </form>
    </section>
    </div>

    {{-- ============ Area filters ============ --}}
    <form method="GET" id="cov-form" class="filter-bar filter-sm mb-3" data-filter data-filter-region data-loader-text="Calculating coverage…">
        @foreach (collect($keep)->only(['type', 'ref', 'from', 'to']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        <div class="row g-2 align-items-end">
            @if ($districts->isNotEmpty())
                <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="cv-d">District</label>
                    <select id="cv-d" name="district_id" class="form-select form-select-sm" data-autosubmit onchange="this.form.block_id && (this.form.block_id.value=''); this.form.cluster_id && (this.form.cluster_id.value='')"><option value="">All districts</option>
                        @foreach ($districts as $d)<option value="{{ $d->id }}" @selected($filters['district_id'] === $d->id)>{{ $d->label }}</option>@endforeach</select></div>
            @endif
            @if ($blocks->isNotEmpty())
                <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="cv-b">Block</label>
                    <select id="cv-b" name="block_id" class="form-select form-select-sm" data-autosubmit onchange="this.form.cluster_id && (this.form.cluster_id.value='')"><option value="">All blocks</option>
                        @foreach ($blocks as $b)<option value="{{ $b->id }}" @selected($filters['block_id'] === $b->id)>{{ $b->label }}</option>@endforeach</select></div>
            @endif
            @if ($clusters->isNotEmpty())
                <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="cv-c">Cluster</label>
                    <select id="cv-c" name="cluster_id" class="form-select form-select-sm" data-autosubmit><option value="">All clusters</option>
                        @foreach ($clusters as $c)<option value="{{ $c->id }}" @selected($filters['cluster_id'] === $c->id)>{{ $c->label }}</option>@endforeach</select></div>
            @endif
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="cv-cat">School category</label>
                <select id="cv-cat" name="category_id" class="form-select form-select-sm" data-autosubmit><option value="">All</option>
                    @foreach ($categories as $c)<option value="{{ $c->id }}" @selected($filters['category_id'] === $c->id)>{{ $c->label }}</option>@endforeach</select></div>
            <div class="col-6 col-md-2"><label class="form-label text-xs mb-1" for="cv-sort">Order by</label>
                <select id="cv-sort" name="sort" class="form-select form-select-sm" data-autosubmit>
                    @foreach ($sorts as $k => $l)<option value="{{ $k }}" @selected($filters['sort'] === $k)>{{ $l }}</option>@endforeach</select></div>
            @if ($level)<input type="hidden" name="level" value="{{ $level }}">@endif
            <div class="col-12 col-md-auto ms-md-auto"><x-filter-buttons :reset="route('reports.coverage', collect($keep)->only(['type', 'ref', 'from', 'to'])->all())" /></div>
        </div>
    </form>

    <div id="cov-results" data-filter-region data-filter-results>
    {{-- ============ 26-11-2026: the two figures in plain words (same sentences as the downloads) ============ --}}
    <section class="cov-explain mb-3" data-cov-explain>
        <div class="cov-explain-head">
            <h3><i class="bi bi-lightbulb"></i>What this report shows</h3>
            <button type="button" class="btn btn-sm btn-light" data-cov-explain-toggle aria-expanded="true"><span data-show-text>Hide</span></button>
        </div>
        <div class="cov-explain-body">
            <div class="cov-explain-item tone-{{ $s['band']['tone'] }}">
                <span class="cov-explain-title"><i class="bi bi-geo-alt"></i>Visit coverage · {{ $pct($s['visit_pct']) }}</span>
                <p>{{ $explain['visit'] }}</p>
            </div>
            <div class="cov-explain-item tone-{{ $s['insp_band']['tone'] }}">
                <span class="cov-explain-title"><i class="bi bi-patch-check"></i>Inspection coverage · {{ $pct($s['insp_pct']) }}</span>
                <p>{{ $explain['inspection'] }}</p>
            </div>
        </div>
        <div class="cov-explain-foot"><i class="bi bi-palette"></i>Colours: {{ $explain['bands'] }}. Tap a district, block or cluster name to see the areas inside it.</div>
    </section>

    {{-- ============ KPIs (hover or tap a tile for its meaning) ============ --}}
    <div class="row g-2 g-md-3 mb-3">
        <div class="col-6 col-xl-2"><x-stat-card wrap label="Schools in this area" :value="number_format($s['schools'])" icon="bi-buildings" tone="primary" hint="active schools" data-bs-toggle="tooltip" tabindex="0" title="Active schools of this area in the School Master. Both percentages are out of this number." /></div>
        <div class="col-6 col-xl-2"><x-stat-card wrap label="Schools visited" :value="number_format($s['visited'])" icon="bi-geo-alt" tone="accent" hint="at least one counted visit" data-bs-toggle="tooltip" tabindex="0" :title="'Schools that got at least one visit in the period with the officer\'s location within '.$radius.' m of the school. A school visited 3 times counts once.'" /></div>
        <div class="col-6 col-xl-2">
            <div class="card stat-card stat-card-wrap tone-edge-{{ $s['band']['tone'] }} h-100" data-bs-toggle="tooltip" tabindex="0" title="Schools visited ÷ schools in this area. It shows how many schools were seen at least once in the period."><div class="card-body d-flex align-items-center gap-3">
                {!! $ring($s['visit_pct'], $s['band']['tone']) !!}
                <div class="min-w-0"><div class="stat-value">{{ $pct($s['visit_pct']) }}</div><div class="stat-label stat-label-wrap">Visit coverage</div>
                    <span class="cov-band tone-{{ $s['band']['tone'] }}">{{ $s['band']['label'] }}</span></div>
            </div></div>
        </div>
        <div class="col-6 col-xl-2">
            <div class="card stat-card stat-card-wrap tone-edge-{{ $s['insp_band']['tone'] }} h-100" data-bs-toggle="tooltip" tabindex="0" title="Schools with a submitted inspection in the {{ strtolower($setting->cycleLabel()) }} cycle ÷ schools in this area. Drafts do not count here."><div class="card-body d-flex align-items-center gap-3">
                {!! $ring($s['insp_pct'], $s['insp_band']['tone']) !!}
                <div class="min-w-0"><div class="stat-value">{{ $pct($s['insp_pct']) }}</div><div class="stat-label stat-label-wrap">Inspection coverage</div>
                    <div class="stat-hint">{{ number_format($s['inspected']) }} inspected this {{ strtolower($setting->cycleLabel()) }} cycle</div></div>
            </div></div>
        </div>
        <div class="col-6 col-xl-2"><x-stat-card wrap label="Not visited" :value="number_format($s['not_visited'])" icon="bi-exclamation-triangle" tone="danger" :hint="number_format($s['due']).' not inspected this cycle'" data-bs-toggle="tooltip" tabindex="0" title="Schools with no counted visit in the period. Open a cluster to see their names (not visited first)." /></div>
        <div class="col-6 col-xl-2"><x-stat-card wrap label="Total visits" :value="number_format($s['visits'])" icon="bi-clipboard-check" tone="success" :hint="$s['repeat'].' repeat · '.$s['unverified'].' not counted · '.$s['officers'].' officers'" data-bs-toggle="tooltip" tabindex="0" title="Counted visits in the period. Repeat = a second or later visit to the same school. Not counted = the location was missing or too far from the school." /></div>
    </div>

    {{-- ============ Area tables ============ --}}
    @if ($level)
        <section class="card cov-card mb-3">
            <div class="cov-card-head">
                <ul class="nav cov-tabs" role="tablist">
                    @foreach ($levels as $lv)
                        <li><a class="{{ $lv === $level ? 'active' : '' }}" href="{{ route('reports.coverage', array_merge($keep, ['level' => $lv])) }}">{{ \App\Services\CoverageReportService::LEVELS[$lv] }} <span>{{ $lv === $level ? $rows->count() : '' }}</span></a></li>
                    @endforeach
                </ul>
                <span class="cov-order"><i class="bi bi-sort-down"></i>{{ $sorts[$filters['sort']] }}</span>
            </div>
            <div class="rv-table-wrap cov-table-wrap">
                <table class="rv-table cov-table">
                    <thead><tr>
                        @php $th = fn ($label, $tip, $attr = '') => '<th '.$attr.'><span class="cov-th" data-bs-toggle="tooltip" tabindex="0" title="'.e($tip).'">'.e($label).'<i class="bi bi-info-circle"></i></span></th>'; @endphp
                        <th>Sl.</th><th>{{ ucfirst($level) }}</th>
                        {!! $th('Schools', 'Active schools in the School Master.', 'class="r"') !!}
                        {!! $th('Visited', 'Schools with at least one counted visit in the period.', 'class="r"') !!}
                        {!! $th('Visit coverage', 'Visited ÷ schools. The colour shows the band.', 'style="min-width:150px"') !!}
                        {!! $th('Inspected this cycle', 'Schools with a submitted inspection in the inspection cycle.', 'class="r"') !!}
                        {!! $th('Inspection coverage', 'Inspected this cycle ÷ schools.', 'style="min-width:130px"') !!}
                        {!! $th('Not visited', 'Schools with no counted visit in the period.', 'class="r"') !!}
                        {!! $th('Visits', 'All counted visits, with repeat visits and visits not counted (location missing or too far).', 'class="r"') !!}
                        {!! $th('Officers', 'Officers who made at least one counted visit.', 'class="r"') !!}
                        {!! $th('Last visit', 'Date of the latest counted visit.') !!}
                    </tr></thead>
                    <tbody>
                    @foreach ($rows as $i => $r)
                        <tr>
                            <td class="rv-sl">{{ $i + 1 }}</td>
                            <td class="rv-wide"><a href="{{ $drill($r) }}" class="cov-area">{{ $r['name'] }}<i class="bi bi-chevron-right"></i></a>@if ($r['parent'])<span class="rv-sub">{{ $r['parent'] }}</span>@endif</td>
                            <td class="r" data-label="Schools"><b>{{ number_format($r['schools']) }}</b></td>
                            <td class="r" data-label="Visited"><b class="text-primary">{{ number_format($r['visited']) }}</b></td>
                            <td data-label="Visit coverage" class="rv-wide">
                                <div class="cov-bar tone-{{ $r['band']['tone'] }}"><i style="width: {{ min(100, $r['visit_pct'] ?? 0) }}%"></i></div>
                                <span class="cov-pct">{{ $pct($r['visit_pct']) }}</span> <span class="cov-band tone-{{ $r['band']['tone'] }}">{{ $r['band']['label'] }}</span>
                            </td>
                            <td class="r" data-label="Inspected this cycle">{{ number_format($r['inspected']) }}</td>
                            <td data-label="Inspection coverage">
                                <div class="cov-bar thin tone-{{ $r['insp_band']['tone'] }}"><i style="width: {{ min(100, $r['insp_pct'] ?? 0) }}%"></i></div>
                                <span class="cov-pct">{{ $pct($r['insp_pct']) }}</span>
                            </td>
                            <td class="r" data-label="Not visited">@if ($r['not_visited'])<span class="text-danger fw-semibold">{{ number_format($r['not_visited']) }}</span>@else<span class="text-success"><i class="bi bi-check2-all"></i></span>@endif</td>
                            <td class="r" data-label="Visits">{{ $r['visits'] }}@if ($r['repeat'])<span class="rv-sub">{{ $r['repeat'] }} repeat</span>@endif @if ($r['unverified'])<span class="rv-sub text-warning">{{ $r['unverified'] }} not counted</span>@endif</td>
                            <td class="r" data-label="Officers">{{ $r['officers'] }}</td>
                            <td data-label="Last visit">{{ $r['last'] ? \Illuminate\Support\Carbon::parse($r['last'])->format('d M Y') : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr class="cov-total">
                        <td></td><td>Total</td><td class="r">{{ number_format($s['schools']) }}</td><td class="r">{{ number_format($s['visited']) }}</td><td>{{ $pct($s['visit_pct']) }}</td>
                        <td class="r">{{ number_format($s['inspected']) }}</td><td>{{ $pct($s['insp_pct']) }}</td><td class="r">{{ number_format($s['not_visited']) }}</td><td class="r">{{ $s['visits'] }}</td><td class="r">{{ $s['officers'] }}</td><td>{{ $s['last'] ? \Illuminate\Support\Carbon::parse($s['last'])->format('d M Y') : '—' }}</td>
                    </tr></tfoot>
                </table>
                @if ($rows->isEmpty())<x-empty-state icon="bi-pie-chart" title="No areas found" text="There are no areas for the selected filters." />@endif
            </div>
        </section>
    @endif

    {{-- ============ Schools of one cluster: not visited first ============ --}}
    @if ($filters['cluster_id'])
        <section class="card cov-card mb-3">
            <div class="cov-card-head"><h3 class="cov-title"><i class="bi bi-building"></i>Schools · {{ $areaTitle }}</h3>
                <span class="cov-order">{{ $schools->where('verified', 0)->count() }} not visited · {{ $schools->where('inspected', false)->count() }} not inspected this cycle</span></div>
            <p class="cov-note"><i class="bi bi-info-circle"></i>Schools not visited are listed first. "Visit not counted" means the officer went, but the location was missing or more than {{ $radius }} m from the school.</p>
            <div class="rv-table-wrap cov-table-wrap">
                <table class="rv-table cov-table">
                    <thead><tr><th>Sl.</th><th>School</th><th>Visit in the period</th><th class="r">Counted visits</th><th>Last visit</th><th>Inspection this cycle</th></tr></thead>
                    <tbody>
                    @forelse ($schools as $i => $r)
                        <tr class="{{ $r['verified'] ? '' : 'cov-missed' }}">
                            <td class="rv-sl">{{ $i + 1 }}</td>
                            <td class="rv-wide">@can('conduct_inspections')<a href="{{ route('schools.show', $r['school']) }}" class="rv-name">{{ $r['school']->name }}</a>@else<span class="rv-name">{{ $r['school']->name }}</span>@endcan
                                <span class="rv-sub font-monospace">UDISE {{ $r['school']->udise_code }}@unless ($r['gps']) · <span class="text-warning">no GPS in master</span>@endunless</span></td>
                            <td data-label="Visit status">
                                @if ($r['verified'])<span class="rs-chip ok"><i class="bi bi-geo-alt-fill"></i>Visited {{ $r['verified'] }} {{ $r['verified'] === 1 ? 'time' : 'times' }}</span>
                                @elseif ($r['unverified'])<span class="rs-chip warn"><i class="bi bi-question-circle"></i>Visit not counted (location){{ $r['unverified'] > 1 ? ' · '.$r['unverified'] : '' }}</span>
                                @else<span class="rs-chip bad"><i class="bi bi-x-circle"></i>Not visited</span>@endif
                            </td>
                            <td class="r" data-label="Counted visits">{{ $r['verified'] }}</td>
                            <td data-label="Last visit">{{ $r['last'] ? \Illuminate\Support\Carbon::parse($r['last'])->format('d M Y') : '—' }}@if ($r['last_officer'])<span class="rv-sub">{{ $r['last_officer'] }}</span>@endif</td>
                            <td data-label="Inspection this cycle">@if ($r['inspected'])<span class="rs-chip ok"><i class="bi bi-patch-check-fill"></i>Inspected</span>@else<span class="rs-chip warn"><i class="bi bi-hourglass-split"></i>Not inspected yet</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-empty-state icon="bi-building" title="No schools" text="This cluster has no active school in the School Master." /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- ============ How it is counted (inside the results, so it follows the filters) ============ --}}
    <details class="cov-help">
        <summary><i class="bi bi-question-circle"></i>Questions about this report</summary>
        {{-- 26-11-2026: short questions and answers with an example each --}}
        <dl>
            <dt>Which schools are counted?</dt><dd>The <b>active</b> schools of the area in the School Master. Both percentages are out of this number.</dd>
            <dt>What is a visit?</dt><dd>Any inspection the officer started at the school (draft or submitted, not cancelled). <i>Example: an officer starts an inspection at a school on 10 Nov; the school counts as visited in November, even if the form is finished later.</i></dd>
            <dt>When is a visit not counted?</dt><dd>When neither the start location, the submit location nor a photo location is within <b>{{ $radius }} m</b> of the school (for example GPS was off, or the form was started from the office). A school with no GPS in the School Master accepts any captured location.</dd>
            <dt>What is the coverage period?</dt><dd>The dates the visits are counted for: <b>{{ $period->label() }}</b> ({{ $period->from->format('d M Y') }} – {{ $period->to->format('d M Y') }}). Use Daily, Weekly, Monthly, Quarterly or Custom above, or the arrows for the previous / next period.</dd>
            <dt>What is the inspection cycle?</dt><dd>How often every school must have a <b>submitted</b> inspection: <b>{{ strtolower($setting->cycleLabel()) }}</b>, now {{ $cycle->from->format('d M Y') }} – {{ $cycle->to->format('d M Y') }}. A school without one is <b>not inspected yet</b> (due). Drafts do not count here.</dd>
            <dt>Why are the two percentages different?</dt><dd>Visit coverage looks at the period and counts any visit; Inspection coverage looks at the whole cycle and counts only submitted forms. <i>Example: a school visited in October and submitted then is not "visited" in November, but is "inspected" for the October–December quarter.</i></dd>
            <dt>What do repeat visits mean?</dt><dd>A second or later counted visit to a school already visited in the period. The school is still counted once.</dd>
            <dt>What do the colours mean?</dt><dd>{{ $explain['bands'] }}.</dd>
            <dt>Who sets the period and the cycle?</dt><dd>The State Admin sets the state default; a district can have its own (Coverage Settings). This view uses the <b>{{ $setting->district_id ? $setting->district?->label.' district setting' : 'state default' }}</b>.</dd>
        </dl>
    </details>
    </div>

    @push('scripts')
        <script>
            // 26-11-2026: "What this report shows" can be folded away; the choice is remembered (sections swapped by the filters too)
            (() => {
                const KEY = 'si-cov-help';
                const apply = () => document.querySelectorAll('[data-cov-explain]').forEach((box) => {
                    const hidden = localStorage.getItem(KEY) === 'hidden';
                    box.classList.toggle('is-folded', hidden);
                    const btn = box.querySelector('[data-cov-explain-toggle]');
                    btn.setAttribute('aria-expanded', hidden ? 'false' : 'true');
                    btn.querySelector('[data-show-text]').textContent = hidden ? 'Show' : 'Hide';
                });
                document.addEventListener('click', (e) => {
                    if (!e.target.closest('[data-cov-explain-toggle]')) return;
                    localStorage.setItem(KEY, localStorage.getItem(KEY) === 'hidden' ? 'shown' : 'hidden');
                    apply();
                });
                document.addEventListener('si:content', apply);
                apply();
            })();
        </script>
    @endpush
</x-layouts.app>
