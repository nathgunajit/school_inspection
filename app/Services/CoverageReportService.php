<?php

namespace App\Services;

use App\Enums\InspectionStatus;
use App\Exports\ArrayExport;
use App\Exports\SchoolTemplateExport;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\CoverageSetting;
use App\Models\District;
use App\Models\Inspection;
use App\Models\MasterValue;
use App\Models\School;
use App\Models\User;
use App\Support\CoveragePeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inspection Coverage Report: how many of the assigned schools were covered in the configured period.
 *
 *  - Assigned schools: active schools in the area (School Master), within the viewer's jurisdiction.
 *  - Visit Coverage: schools with at least one VERIFIED visit in the period. A visit is any inspection that is
 *    not cancelled (draft, submitted, unlocked) whose start / submit location or a geo-tagged photo lies within
 *    the verification radius of the school; when the school has no GPS in the master, any captured location counts.
 *  - Inspection Coverage: schools with a SUBMITTED (or unlocked) inspection inside the prescribed inspection-cycle
 *    window that contains the period's end date (or today while the period is still running).
 *
 * Areas use the school's own district / block / cluster, so totals, visits and the cycle always agree.
 * The number of queries does not grow with the number of areas; verification runs in PHP (haversine),
 * so it works the same on MySQL and SQLite.
 */
class CoverageReportService
{
    public const SORTS = [
        'unique' => 'Unique schools visited (high → low)',
        'visit_pct' => 'Visit coverage % (high → low)',
        'insp_pct' => 'Inspection coverage % (high → low)',
        'schools' => 'Total schools (high → low)',
        'not_visited' => 'Not visited (high → low)',
        'name' => 'Name (A → Z)',
    ];

    public const LEVELS = ['district' => 'District-wise', 'block' => 'Block-wise', 'cluster' => 'Cluster-wise'];

    /** @var array<string, array> per-request cache of the computed data */
    private array $memo = [];

    public function __construct(private JurisdictionService $j) {}

    /* ======================= filters, setting, period ======================= */

    /** Validated filters; area ids are narrowed to the viewer's jurisdiction (never trusted from the client). */
    public function filters(Request $request, User $user): array
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:'.implode(',', array_keys(CoverageSetting::PERIODS))],
            'ref' => ['nullable', 'date'],
            'from' => ['nullable', 'date', 'required_if:type,custom'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'required_if:type,custom'],
            'district_id' => ['nullable', 'integer'],
            'block_id' => ['nullable', 'integer'],
            'cluster_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORTS))],
            'level' => ['nullable', 'in:district,block,cluster'],
        ], ['to.after_or_equal' => 'The "to" date must be on or after the "from" date.']);

        $jur = $this->j->current($user);
        $f = [
            'type' => $data['type'] ?? null,
            'ref' => $request->date('ref')?->toDateString(),
            'from' => $request->date('from')?->toDateString(),
            'to' => $request->date('to')?->toDateString(),
            'district_id' => isset($data['district_id']) ? (int) $data['district_id'] : null,
            'block_id' => isset($data['block_id']) ? (int) $data['block_id'] : null,
            'cluster_id' => isset($data['cluster_id']) ? (int) $data['cluster_id'] : null,
            'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : null,
            'sort' => $data['sort'] ?? 'unique',
            'level' => $data['level'] ?? null,
        ];
        // the viewer's own area always applies (a District officer cannot look at another district)
        if (! $user->level->isStateWide()) {
            $f['district_id'] = $jur?->district_id;
            if (in_array($user->level->value, ['block', 'cluster'], true)) {
                $f['block_id'] = $jur?->block_id;
            }
            if ($user->level->value === 'cluster') {
                $f['cluster_id'] = $jur?->cluster_id;
            }
        }
        // a lower area implies its parents (keeps the cascade and the drill-down consistent)
        if ($f['cluster_id'] && ! $f['block_id']) {
            $f['block_id'] = Cluster::whereKey($f['cluster_id'])->value('block_id');
        }
        if ($f['block_id'] && ! $f['district_id']) {
            $f['district_id'] = Block::whereKey($f['block_id'])->value('district_id');
        }

        return $f;
    }

    /** The coverage setting for the area in view: the district's own, else the state default. */
    public function setting(array $f): CoverageSetting
    {
        return CoverageSetting::forDistrict($f['district_id']);
    }

    /** Metres; 0 = any distance (28-11-2026: every not-cancelled visit counts, wherever the officer was). */
    public function radius(): int
    {
        $m = (int) CoverageSetting::state()->verify_radius_m;

        return $m === 0 ? 0 : max(50, $m);
    }

    public function anyDistance(): bool
    {
        return $this->radius() === 0;
    }

    /** The plain sentence about the radius (report, summary, downloads). */
    public function radiusSentence(): string
    {
        return $this->anyDistance()
            ? 'A visit counts at any distance from the school, wherever the officer was.'
            : 'A visit counts when the officer is within '.$this->radius().' m of the school.';
    }

    /** The coverage period: the viewer's choice, else the configured type, around ?ref= (default today). */
    public function period(array $f): CoveragePeriod
    {
        $s = $this->setting($f);
        $type = $f['type'] ?? $s->period_type;
        $ref = $f['ref'] ? Carbon::parse($f['ref']) : today();
        if ($type === 'custom') {
            $from = $f['from'] ? Carbon::parse($f['from']) : ($f['type'] === null ? $s->custom_from : null);
            $to = $f['to'] ? Carbon::parse($f['to']) : ($f['type'] === null ? $s->custom_to : null);

            return CoveragePeriod::custom($from ?? $ref->copy()->startOfMonth(), $to ?? $ref);
        }

        return CoveragePeriod::forType($type, $ref);
    }

    /**
     * The inspection-cycle window that contains the end of the period, or today while the period is still running.
     * 26-11-2026: before, the current quarter (ending 31 Dec) with a monthly cycle used December, a month not yet
     * started, so Inspection coverage was always 0 %.
     */
    public function cycle(array $f): CoveragePeriod
    {
        $end = $this->period($f)->to;
        $anchor = $end->greaterThan(today()) ? today() : $end;

        return CoveragePeriod::cycle($this->setting($f)->inspection_cycle, $anchor);
    }

    /** Level tabs available for the viewer and filters (empty = one cluster: show its schools). */
    public function levels(User $user, array $f): array
    {
        if ($f['cluster_id']) {
            return [];
        }
        if ($f['block_id']) {
            return ['cluster'];
        }
        if ($f['district_id']) {
            return ['block', 'cluster'];
        }

        return ['district', 'block', 'cluster'];
    }

    public function level(User $user, array $f): ?string
    {
        $levels = $this->levels($user, $f);

        return in_array($f['level'], $levels, true) ? $f['level'] : ($levels[0] ?? null);
    }

    /* ======================= data ======================= */

    /** Active schools in the jurisdiction and filters. */
    private function schoolQuery(User $user, array $f): Builder
    {
        return $this->j->scope(School::query(), $user, 'schools')
            ->where('schools.status', 'active')
            ->when($f['district_id'], fn ($q, $v) => $q->where('schools.district_id', $v))
            ->when($f['block_id'], fn ($q, $v) => $q->where('schools.block_id', $v))
            ->when($f['cluster_id'], fn ($q, $v) => $q->where('schools.cluster_id', $v))
            ->when($f['category_id'], fn ($q, $v) => $q->where('schools.category_id', $v));
    }

    /**
     * Everything the page needs, computed once: per-cluster rows (with district / block ids), per-school visit
     * facts and the cycle set.
     *
     * @return array{clusters: Collection, schools: array, cycle: array}
     */
    public function data(User $user, array $f): array
    {
        $key = $user->id.'|'.md5(json_encode($f));
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $period = $this->period($f);
        $cycle = $this->cycle($f);
        $radius = $this->radius();

        // 1. assigned schools per cluster
        $totals = (clone $this->schoolQuery($user, $f))->toBase()
            ->selectRaw('schools.district_id, schools.block_id, schools.cluster_id, count(*) as n')
            ->groupBy('schools.district_id', 'schools.block_id', 'schools.cluster_id')->get();

        // 2. visits in the period (not cancelled), verified in PHP against the school's location
        $perSchool = [];
        (clone $this->schoolQuery($user, $f))->toBase()
            ->join('inspections', 'inspections.school_id', '=', 'schools.id')
            ->tap(fn ($q) => \App\Support\InspectionVisibility::apply($q, $user)) // 25-11-2026: own + lower levels
            ->where('inspections.status', '!=', InspectionStatus::Cancelled->value)
            ->where('inspections.inspection_date', '>=', $period->fromDate())
            ->where('inspections.inspection_date', '<=', $period->toDate())
            ->select('inspections.id', 'inspections.school_id', 'inspections.officer_id', 'inspections.inspection_date',
                'inspections.start_lat', 'inspections.start_lng', 'inspections.submit_lat', 'inspections.submit_lng',
                'schools.cluster_id', 'schools.latitude as s_lat', 'schools.longitude as s_lng')
            ->orderBy('inspections.id')
            ->chunk(1000, function ($rows) use (&$perSchool, $radius) {
                $photos = DB::table('inspection_evidence')->whereIn('inspection_id', $rows->pluck('id'))
                    ->where('kind', 'photo')->whereNotNull('latitude')->whereNotNull('longitude')
                    ->get(['inspection_id', 'latitude', 'longitude'])->groupBy('inspection_id');
                foreach ($rows as $r) {
                    $points = [[$r->start_lat, $r->start_lng], [$r->submit_lat, $r->submit_lng]];
                    foreach ($photos->get($r->id, []) as $p) {
                        $points[] = [$p->latitude, $p->longitude];
                    }
                    $points = array_values(array_filter($points, fn ($p) => $p[0] !== null && $p[1] !== null));
                    $schoolGps = $r->s_lat !== null && $r->s_lng !== null;
                    $verified = $radius === 0 || $points && (! $schoolGps || collect($points)->contains(
                        fn ($p) => Inspection::haversine((float) $p[0], (float) $p[1], (float) $r->s_lat, (float) $r->s_lng) <= $radius));

                    $s = $perSchool[$r->school_id] ??= ['cluster_id' => (int) $r->cluster_id, 'verified' => 0, 'unverified' => 0, 'last' => null, 'officers' => [], 'last_officer' => null];
                    $verified ? $s['verified']++ : $s['unverified']++;
                    if ($verified) {
                        $s['officers'][(int) $r->officer_id] = true;
                        $date = substr((string) $r->inspection_date, 0, 10);
                        if ($s['last'] === null || $date >= $s['last']) {
                            $s['last'] = $date;
                            $s['last_officer'] = (int) $r->officer_id;
                        }
                    }
                    $perSchool[$r->school_id] = $s;
                }
            });

        // 3. formal inspections (submitted / unlocked) in the cycle window: school id => cluster id
        $cycleSet = (clone $this->schoolQuery($user, $f))->toBase()
            ->join('inspections', 'inspections.school_id', '=', 'schools.id')
            ->tap(fn ($q) => \App\Support\InspectionVisibility::apply($q, $user))
            ->whereIn('inspections.status', [InspectionStatus::Submitted->value, InspectionStatus::Unlocked->value])
            ->where('inspections.inspection_date', '>=', $cycle->fromDate())
            ->where('inspections.inspection_date', '<=', $cycle->toDate())
            ->distinct()->pluck('schools.cluster_id', 'schools.id')->map(fn ($c) => (int) $c)->all();

        // 4. roll up per cluster (every cluster in the area is listed, also with no school or visit)
        $clusters = Cluster::query()->join('blocks', 'blocks.id', '=', 'clusters.block_id')
            ->when($f['district_id'], fn ($q, $v) => $q->where('blocks.district_id', $v))
            ->when($f['block_id'], fn ($q, $v) => $q->where('clusters.block_id', $v))
            ->when($f['cluster_id'], fn ($q, $v) => $q->where('clusters.id', $v))
            ->get(['clusters.id', 'clusters.name', 'clusters.code', 'clusters.block_id', 'blocks.district_id'])
            ->keyBy('id')
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->label, 'code' => $c->code, 'block_id' => (int) $c->block_id, 'district_id' => (int) $c->district_id,
                'schools' => 0, 'visited' => 0, 'visits' => 0, 'unverified' => 0, 'inspected' => 0, 'officers' => [], 'last' => null]);
        foreach ($totals as $t) {
            if ($clusters->has($t->cluster_id)) {
                $clusters->put($t->cluster_id, ['schools' => (int) $t->n] + $clusters->get($t->cluster_id));
            }
        }
        $clusters = $clusters->all();
        foreach ($perSchool as $s) {
            if (! isset($clusters[$s['cluster_id']])) {
                continue;
            }
            $c = &$clusters[$s['cluster_id']];
            $c['visits'] += $s['verified'];
            $c['unverified'] += $s['unverified'];
            if ($s['verified'] > 0) {
                $c['visited']++;
                $c['officers'] += $s['officers'];
                $c['last'] = max($c['last'] ?? '', $s['last']);
            }
            unset($c);
        }
        foreach ($cycleSet as $clusterId) {
            if (isset($clusters[$clusterId])) {
                $clusters[$clusterId]['inspected']++;
            }
        }

        return $this->memo[$key] = ['clusters' => collect($clusters), 'schools' => $perSchool, 'cycle' => $cycleSet];
    }

    /* ======================= views of the data ======================= */

    /** Derived columns + band for one aggregated row. */
    private function finish(array $r): array
    {
        $r['officers'] = is_array($r['officers']) ? count($r['officers']) : (int) $r['officers'];
        $r['not_visited'] = max(0, $r['schools'] - $r['visited']);
        $r['repeat'] = max(0, $r['visits'] - $r['visited']);
        $r['due'] = max(0, $r['schools'] - $r['inspected']);
        $r['visit_pct'] = $r['schools'] ? round($r['visited'] / $r['schools'] * 100, 1) : null;
        $r['insp_pct'] = $r['schools'] ? round($r['inspected'] / $r['schools'] * 100, 1) : null;
        $r['band'] = self::band($r['visit_pct']);
        $r['insp_band'] = self::band($r['insp_pct']);

        return $r;
    }

    /** ['label' => 'Good', 'tone' => 'success'] for a coverage %. */
    public static function band(?float $pct): array
    {
        if ($pct === null) {
            return ['label' => 'No schools', 'tone' => 'secondary'];
        }
        $bands = config('inspection.coverage.bands');
        krsort($bands);
        foreach ($bands as $min => [$label, $tone]) {
            if ($pct >= $min) {
                return ['label' => $label, 'tone' => $tone];
            }
        }

        return ['label' => 'Critical', 'tone' => 'danger'];
    }

    private static function sum(Collection $rows): array
    {
        $out = ['schools' => 0, 'visited' => 0, 'visits' => 0, 'unverified' => 0, 'inspected' => 0, 'officers' => [], 'last' => null];
        foreach ($rows as $r) {
            foreach (['schools', 'visited', 'visits', 'unverified', 'inspected'] as $k) {
                $out[$k] += $r[$k];
            }
            $out['officers'] += $r['officers'];
            if ($r['last'] && $r['last'] > ($out['last'] ?? '')) {
                $out['last'] = $r['last'];
            }
        }

        return $out;
    }

    public function summary(User $user, array $f): array
    {
        return $this->finish(self::sum($this->data($user, $f)['clusters']));
    }

    /** One row per area of a level, sorted. */
    public function areas(User $user, array $f, string $level): Collection
    {
        $clusters = $this->data($user, $f)['clusters'];
        if ($level === 'cluster') {
            $blocks = Block::with('district:id,code,name')->whereIn('id', $clusters->pluck('block_id')->unique())->get(['id', 'code', 'name', 'district_id'])->keyBy('id');
            $rows = $clusters->map(fn ($c) => $this->finish($c + ['level' => 'cluster',
                'parent' => trim(($blocks[$c['block_id']]->label ?? '').' · '.($blocks[$c['block_id']]?->district?->label ?? ''), ' ·')]));
        } else {
            $col = $level.'_id';
            $model = $level === 'district' ? District::class : Block::class;
            $masters = $model::query()->whereIn('id', $clusters->pluck($col)->unique())
                ->when($level === 'block', fn ($q) => $q->with('district:id,code,name'))->get()->keyBy('id');
            $rows = $clusters->groupBy($col)->map(fn ($g, $id) => $this->finish(self::sum($g) + [
                'id' => (int) $id, 'name' => $masters[$id]->label ?? '—', 'code' => $masters[$id]->code ?? '', 'level' => $level,
                'block_id' => $level === 'block' ? (int) $id : null, 'district_id' => $level === 'district' ? (int) $id : ($masters[$id]->district_id ?? null),
                'parent' => $level === 'block' ? ($masters[$id]?->district?->label ?? '') : '',
            ]));
        }

        return $this->sortRows($rows->values(), $f['sort']);
    }

    public function sortRows(Collection $rows, string $sort): Collection
    {
        $num = fn ($v) => $v ?? -1;

        return (match ($sort) {
            'visit_pct' => $rows->sortBy([fn ($a, $b) => $num($b['visit_pct']) <=> $num($a['visit_pct']), fn ($a, $b) => $b['visited'] <=> $a['visited']]),
            'insp_pct' => $rows->sortBy([fn ($a, $b) => $num($b['insp_pct']) <=> $num($a['insp_pct']), fn ($a, $b) => $b['inspected'] <=> $a['inspected']]),
            'schools' => $rows->sortBy([fn ($a, $b) => $b['schools'] <=> $a['schools'], fn ($a, $b) => strcmp($a['name'], $b['name'])]),
            'not_visited' => $rows->sortBy([fn ($a, $b) => $b['not_visited'] <=> $a['not_visited'], fn ($a, $b) => strcmp($a['name'], $b['name'])]),
            'name' => $rows->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE),
            default => $rows->sortBy([fn ($a, $b) => $b['visited'] <=> $a['visited'], fn ($a, $b) => $num($b['visit_pct']) <=> $num($a['visit_pct']), fn ($a, $b) => strcmp($a['name'], $b['name'])]),
        })->values();
    }

    /** The schools of the area in view (one cluster), not visited first. */
    public function schools(User $user, array $f): Collection
    {
        $data = $this->data($user, $f);
        $schools = (clone $this->schoolQuery($user, $f))->orderBy('schools.name')->get(['schools.id', 'schools.udise_code', 'schools.name', 'schools.cluster_id', 'schools.latitude', 'schools.longitude']);
        $officerIds = collect($data['schools'])->pluck('last_officer')->filter()->unique();
        $officers = User::whereIn('id', $officerIds)->pluck('name', 'id');

        return $schools->map(function (School $s) use ($data, $officers) {
            $v = $data['schools'][$s->id] ?? null;

            return [
                'school' => $s,
                'verified' => $v['verified'] ?? 0,
                'unverified' => $v['unverified'] ?? 0,
                'last' => $v['last'] ?? null,
                'last_officer' => isset($v['last_officer']) ? ($officers[$v['last_officer']] ?? null) : null,
                'inspected' => isset($data['cycle'][$s->id]),
                'gps' => $s->hasCoordinates(),
            ];
        })->sortBy([fn ($a, $b) => ($a['verified'] > 0) <=> ($b['verified'] > 0), fn ($a, $b) => strcmp($a['school']->name, $b['school']->name)])->values();
    }

    /** Filter lists, limited to the viewer's jurisdiction. */
    public function options(User $user, array $f): array
    {
        return [
            'districts' => $user->level->isStateWide() ? District::orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'blocks' => $f['district_id'] && in_array($user->level->value, ['state_admin', 'state', 'district'], true)
                ? Block::where('district_id', $f['district_id'])->orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'clusters' => $f['block_id'] && $user->level->value !== 'cluster' ? Cluster::where('block_id', $f['block_id'])->orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'categories' => MasterValue::where('type', 'category')->orderBy('sort')->get(['id', 'label']),
        ];
    }

    /** Name of the area in view, e.g. "North District › Riverside Block". */
    public function areaTitle(array $f): string
    {
        $parts = array_filter([
            $f['district_id'] ? District::whereKey($f['district_id'])->value('name') : null,
            $f['block_id'] ? Block::whereKey($f['block_id'])->value('name') : null,
            $f['cluster_id'] ? Cluster::whereKey($f['cluster_id'])->value('name') : null,
        ]);

        return $parts ? implode(' › ', $parts) : 'All districts';
    }

    /* ======================= Excel ======================= */

    /**
     * 23-11-2026 (V14): the Excel holds the view on screen, like the PDF: Summary, the selected level (the active
     * tab) or the selected cluster's schools, then the not-visited and due lists of that area.
     */
    public function export(User $user, array $f): SchoolTemplateExport
    {
        $sheets = [[new ArrayExport(self::SUMMARY_HEADS, $this->summaryRows($user, $f)), 'Summary']];

        if ($level = $this->level($user, $f)) {
            $sheets[] = [new ArrayExport(self::AREA_HEADS, $this->areaRows($user, $f, $level)), self::LEVELS[$level]];
        } elseif ($f['cluster_id']) {
            $sheets[] = [new ArrayExport(self::CLUSTER_SCHOOL_HEADS, $this->clusterSchoolRows($user, $f)), 'Schools of the cluster'];
        }

        $schoolRows = $this->schoolsForExport($user, $f);
        $sheets[] = [new ArrayExport(['UDISE', 'School', 'Cluster', 'Block', 'District', 'Unverified visits'],
            $schoolRows->where('verified', 0)->map(fn ($r) => [$r['udise'], $r['name'], $r['cluster'], $r['block'], $r['district'], $r['unverified']])->values()->all()), 'Schools not visited'];
        $sheets[] = [new ArrayExport(['UDISE', 'School', 'Cluster', 'Block', 'District', 'Verified visits in period'],
            $schoolRows->where('inspected', false)->map(fn ($r) => [$r['udise'], $r['name'], $r['cluster'], $r['block'], $r['district'], $r['verified']])->values()->all()), 'Due in cycle'];

        return new SchoolTemplateExport($sheets);
    }

    /* ======================= PDF (23-11-2026) ======================= */

    public const AREA_HEADS = ['Sl.', 'Area', 'Parent', 'Total schools', 'Unique schools visited (verified)', 'Visit coverage %', 'Visit band',
        'Inspected in cycle', 'Inspection coverage %', 'Not visited', 'Due in cycle', 'Verified visits', 'Repeat visits', 'Visits not counted', 'Officers active', 'Last visit'];

    /** The same columns with headings short enough for an A4 landscape page. */
    public const AREA_HEADS_SHORT = ['Sl.', 'Area', 'Parent', 'Schools', 'Visited', 'Visit %', 'Band',
        'Inspected', 'Insp. %', 'Not visited', 'Due', 'Visits', 'Repeat', 'Not counted', 'Officers', 'Last visit'];

    /**
     * What the screen shows, for App\Support\MasterPdf::sections(): the summary, then the area table of the
     * level in view (sorted as on screen, with a Total row), or the school list when one cluster is selected.
     *
     * @return list<array{title: ?string, headings: list<string>, rows: list<array>}>
     */
    public function pdfSections(User $user, array $f): array
    {
        $sections = [['title' => 'Summary', 'headings' => self::SUMMARY_HEADS, 'rows' => $this->summaryRows($user, $f)]];
        if ($level = $this->level($user, $f)) {
            $sections[] = ['title' => self::LEVELS[$level], 'headings' => self::AREA_HEADS_SHORT, 'rows' => $this->areaRows($user, $f, $level)];
        } elseif ($f['cluster_id']) {
            $sections[] = ['title' => 'Schools of '.$this->areaTitle($f), 'rows' => $this->clusterSchoolRows($user, $f), 'headings' => self::CLUSTER_SCHOOL_HEADS];
        }

        return $sections;
    }

    public const CLUSTER_SCHOOL_HEADS = ['Sl.', 'UDISE', 'School', 'Counted visits', 'Not counted', 'Last visit', 'Last officer', 'Inspected in cycle', 'GPS'];

    /** The selected cluster's schools (not visited first), in the CLUSTER_SCHOOL_HEADS order. */
    private function clusterSchoolRows(User $user, array $f): array
    {
        return $this->schools($user, $f)->values()->map(fn ($r, $i) => [$i + 1, $r['school']->udise_code, $r['school']->name,
            $r['verified'], $r['unverified'], $r['last'] ? Carbon::parse($r['last'])->format('d-m-Y') : '—', $r['last_officer'] ?? '—',
            $r['inspected'] ? 'Yes' : 'No', $r['gps'] ? 'Yes' : 'No'])->all();
    }

    /** What the downloads hold, for button labels and file names: "Block-wise", or "Schools" for one cluster. */
    public function viewLabel(User $user, array $f): string
    {
        $level = $this->level($user, $f);

        return $level ? self::LEVELS[$level] : 'Schools';
    }

    /** Rows the PDF would hold (checked against the PDF cap before rendering). */
    public function pdfRowCount(User $user, array $f): int
    {
        if ($level = $this->level($user, $f)) {
            return $this->areas($user, $f, $level)->count();
        }

        return $f['cluster_id'] ? (clone $this->schoolQuery($user, $f))->count() : 0;
    }

    public const SUMMARY_HEADS = ['Item', 'Value', 'What it means'];

    /**
     * 26-11-2026: the two coverage figures of the view in plain sentences, shared by the page card and the
     * downloads, so the screen and the files say the same thing.
     *
     * @return array{visit: string, inspection: string, bands: string}
     */
    public function explain(User $user, array $f): array
    {
        $s = $this->summary($user, $f);
        $period = $this->period($f);
        $cycle = $this->cycle($f);
        $setting = $this->setting($f);
        $pct = fn ($v) => $v === null ? '0 %' : rtrim(rtrim(number_format($v, 1), '0'), '.').' %';
        $area = $this->areaTitle($f);
        $n = fn ($v) => number_format($v);
        $schools = fn ($v) => $n($v).' school'.($v === 1 ? '' : 's');
        // 27-11-2026: wording made plainer (humanizer); ranges read "to", not a dash
        $range = $period->from->isSameDay($period->to) ? $period->from->format('d M Y') : $period->from->format('d M').' to '.$period->to->format('d M Y');
        $when = match ($period->type) {
            'daily' => 'on '.$range,
            'weekly' => 'in the week '.$range,
            'custom' => 'from '.$period->from->format('d M Y').' to '.$period->to->format('d M Y'),
            default => 'in '.$period->label().' ('.$range.')',
        };
        $needs = fn ($v) => $v === 1 ? 'still needs one' : 'still need one';

        return [
            'visit' => $n($s['visited']).' of '.$schools($s['schools']).' ('.$pct($s['visit_pct']).') in '.$area
                .' had at least one visit '.$when.'. '
                .($s['not_visited'] ? $schools($s['not_visited']).' had no visit. ' : 'Every school had a visit. ')
                .($this->anyDistance() ? 'A visit counts at any distance from the school.' : 'A visit counts only if the officer was within '.$this->radius().' m of the school.'),
            'inspection' => $n($s['inspected']).' of '.$schools($s['schools']).' ('.$pct($s['insp_pct']).') '.($s['inspected'] === 1 ? 'has' : 'have')
                .' a submitted inspection in the '.strtolower($setting->cycleLabel()).' cycle ('.$cycle->from->format('d M Y').' to '.$cycle->to->format('d M Y').'). '
                .($s['due'] ? $schools($s['due']).' '.$needs($s['due']).'.' : 'Every school has one.'),
            'bands' => self::bandsText(),
        ];
    }

    /** "Good 75 % and above · Moderate 50–74 % · Low 25–49 % · Critical below 25 %", from config('inspection.coverage.bands'). */
    public static function bandsText(): string
    {
        $bands = config('inspection.coverage.bands');
        krsort($bands);
        $out = [];
        $upper = null;
        foreach ($bands as $min => [$label]) {
            $out[] = $upper === null ? "$label $min % and above"
                : ((int) $min === 0 ? "$label below $upper %" : "$label {$min}–".($upper - 1).' %');
            $upper = (int) $min;
        }

        return implode(' · ', $out);
    }

    /** Summary as Item / Value / What it means rows (Excel Summary sheet and PDF). */
    /**
     * 27-11-2026: the "how this report is counted" sentence for the period in view (shown to every report user, and in
     * the Summary of the downloads), in the words of the Coverage Settings preview. Pieces, so the page can bold them.
     */
    public function howCounted(array $f): array
    {
        $period = $this->period($f);
        $cycle = $this->cycle($f);
        $current = $period->type !== 'custom' && $period->fromDate() === CoveragePeriod::forType($period->type, today())->fromDate();
        $name = match (true) {
            $period->type === 'custom' => 'the custom dates',
            $current => CoveragePeriod::CURRENT_NAMES[$period->type] ?? $period->label(),
            default => $period->label(),
        };

        return [
            'period_name' => $name,
            'period_dates' => $period->from->isSameDay($period->to) ? $period->from->format('d M Y') : $period->from->format('d M Y').' – '.$period->to->format('d M Y'),
            'cycle_name' => mb_strtolower($this->setting($f)->cycleLabel()),
            'cycle_dates' => $cycle->from->format('d M Y').' – '.$cycle->to->format('d M Y'),
            'radius' => $this->radius(),
            'any_distance' => $this->anyDistance(),
        ];
    }

    /** The same sentence as plain text (Excel / PDF Summary). */
    public function howCountedText(array $f): string
    {
        $h = $this->howCounted($f);

        return "It shows {$h['period_name']} ({$h['period_dates']}) and counts the visits made then. A school counts as inspected when an inspection is submitted in the {$h['cycle_name']} cycle, now {$h['cycle_dates']}. ".$this->radiusSentence();
    }

    private function summaryRows(User $user, array $f): array
    {
        $period = $this->period($f);
        $cycle = $this->cycle($f);
        $setting = $this->setting($f);
        $s = $this->summary($user, $f);
        $pct = fn ($v) => $v === null ? '' : $v;
        $say = $this->explain($user, $f);

        return [
            ['How it is counted', $this->howCountedText($f), 'The rules behind every figure below.'], // 27-11-2026
            ['Report period type', $period->typeLabel(), 'The period picked on the report screen.'], // 25-11-2026
            ['Area', $this->areaTitle($f), 'The schools of this area are counted.'],
            ['Coverage period', $period->label().' ('.$period->from->format('d-m-Y').' to '.$period->to->format('d-m-Y').')', 'Visits made between these dates are counted for Visit coverage.'],
            ['Configured period type', $setting->periodLabel().($setting->district_id ? ' (district setting)' : ' (state default)'), 'The period the report opens on, from Coverage Settings.'],
            ['Inspection cycle', $setting->cycleLabel().': '.$cycle->from->format('d-m-Y').' to '.$cycle->to->format('d-m-Y'), 'Every school should have one submitted inspection between these dates.'],
            ['Verification radius', $this->anyDistance() ? 'Any distance' : $this->radius().' m', $this->anyDistance() ? 'Every visit counts, wherever the officer was.' : 'A visit counts only when the officer was this close to the school.'],
            ['Schools in this area', $s['schools'], 'Active schools in the School Master.'],
            ['Schools visited', $s['visited'], 'Schools with at least one counted visit in the period.'],
            ['Visit coverage %', $pct($s['visit_pct']), 'Schools visited ÷ schools in this area × 100.'],
            ['Schools inspected this cycle', $s['inspected'], 'Schools with a submitted inspection in the cycle.'],
            ['Inspection coverage %', $pct($s['insp_pct']), 'Schools inspected this cycle ÷ schools in this area × 100.'],
            ['Not visited', $s['not_visited'], 'Schools with no counted visit in the period.'],
            ['Not inspected this cycle', $s['due'], 'Schools still due a submitted inspection in the cycle.'],
            ['Total visits / repeat / not counted', $s['visits'].' / '.$s['repeat'].' / '.$s['unverified'], 'Repeat = more than one visit to the same school. Not counted = location missing or too far from the school.'],
            ['Officers active', $s['officers'], 'Officers who made at least one counted visit.'],
            ['Visit Coverage', $pct($s['visit_pct']), $say['visit']],
            ['Inspection Coverage', $pct($s['insp_pct']), $say['inspection']],
            ['Colour bands', '', $say['bands']],
        ];
    }

    /** One level's areas (sorted) with a Total row, in the AREA_HEADS order. */
    private function areaRows(User $user, array $f, string $level): array
    {
        $pct = fn ($v) => $v === null ? '' : $v;
        $line = fn ($r, $i) => [$i, $r['name'], $r['parent'] ?? '', $r['schools'], $r['visited'], $pct($r['visit_pct']), $r['band']['label'],
            $r['inspected'], $pct($r['insp_pct']), $r['not_visited'], $r['due'], $r['visits'], $r['repeat'], $r['unverified'], $r['officers'],
            $r['last'] ? Carbon::parse($r['last'])->format('d-m-Y') : ''];
        $data = $this->areas($user, $f, $level)->values()->map(fn ($r, $i) => $line($r, $i + 1))->all();
        $data[] = $line($this->finish(self::sum($this->data($user, $f)['clusters'])) + ['name' => 'Total', 'parent' => ''], '');

        return $data;
    }

    /** Every assigned school in the filters with its visit / cycle facts (export only). */
    private function schoolsForExport(User $user, array $f): Collection
    {
        $data = $this->data($user, $f);
        $out = collect();
        (clone $this->schoolQuery($user, $f))->toBase()
            ->join('clusters', 'clusters.id', '=', 'schools.cluster_id')
            ->join('blocks', 'blocks.id', '=', 'schools.block_id')
            ->join('districts', 'districts.id', '=', 'schools.district_id')
            ->select('schools.id', 'schools.udise_code', 'schools.name', 'clusters.name as cluster', 'blocks.name as block', 'districts.name as district', 'clusters.code as cluster_code', 'blocks.code as block_code', 'districts.code as district_code')
            ->orderBy('districts.name')->orderBy('blocks.name')->orderBy('clusters.name')->orderBy('schools.name')
            ->chunk(2000, function ($rows) use (&$out, $data) {
                foreach ($rows as $r) {
                    $v = $data['schools'][$r->id] ?? null;
                    $out->push(['udise' => $r->udise_code, 'name' => $r->name, 'cluster' => Cluster::labelFor($r->cluster, $r->cluster_code), 'block' => Block::labelFor($r->block, $r->block_code), 'district' => District::labelFor($r->district, $r->district_code),
                        'verified' => $v['verified'] ?? 0, 'unverified' => $v['unverified'] ?? 0, 'inspected' => isset($data['cycle'][$r->id])]);
                }
            });

        return $out;
    }
}
