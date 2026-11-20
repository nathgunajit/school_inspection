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
 *    window that contains the period's end date.
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

    public function radius(): int
    {
        return max(50, (int) CoverageSetting::state()->verify_radius_m);
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

    /** The inspection-cycle window that contains the end of the period. */
    public function cycle(array $f): CoveragePeriod
    {
        return CoveragePeriod::cycle($this->setting($f)->inspection_cycle, $this->period($f)->to);
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
                    $verified = $points && (! $schoolGps || collect($points)->contains(
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
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'block_id' => (int) $c->block_id, 'district_id' => (int) $c->district_id,
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
            $blocks = Block::with('district:id,name')->whereIn('id', $clusters->pluck('block_id')->unique())->get(['id', 'name', 'district_id'])->keyBy('id');
            $rows = $clusters->map(fn ($c) => $this->finish($c + ['level' => 'cluster',
                'parent' => trim(($blocks[$c['block_id']]->name ?? '').' · '.($blocks[$c['block_id']]?->district?->name ?? ''), ' ·')]));
        } else {
            $col = $level.'_id';
            $model = $level === 'district' ? District::class : Block::class;
            $masters = $model::query()->whereIn('id', $clusters->pluck($col)->unique())
                ->when($level === 'block', fn ($q) => $q->with('district:id,name'))->get()->keyBy('id');
            $rows = $clusters->groupBy($col)->map(fn ($g, $id) => $this->finish(self::sum($g) + [
                'id' => (int) $id, 'name' => $masters[$id]->name ?? '—', 'code' => $masters[$id]->code ?? '', 'level' => $level,
                'block_id' => $level === 'block' ? (int) $id : null, 'district_id' => $level === 'district' ? (int) $id : ($masters[$id]->district_id ?? null),
                'parent' => $level === 'block' ? ($masters[$id]?->district?->name ?? '') : '',
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
            'districts' => $user->level->isStateWide() ? District::orderBy('name')->get(['id', 'name']) : collect(),
            'blocks' => $f['district_id'] && in_array($user->level->value, ['state_admin', 'state', 'district'], true)
                ? Block::where('district_id', $f['district_id'])->orderBy('name')->get(['id', 'name']) : collect(),
            'clusters' => $f['block_id'] && $user->level->value !== 'cluster' ? Cluster::where('block_id', $f['block_id'])->orderBy('name')->get(['id', 'name']) : collect(),
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

    public function export(User $user, array $f): SchoolTemplateExport
    {
        $period = $this->period($f);
        $cycle = $this->cycle($f);
        $setting = $this->setting($f);
        $s = $this->summary($user, $f);
        $pct = fn ($v) => $v === null ? '' : $v;
        $heads = ['Sl.', 'Area', 'Parent', 'Total schools', 'Unique schools visited (verified)', 'Visit coverage %', 'Visit band',
            'Inspected in cycle', 'Inspection coverage %', 'Not visited', 'Due in cycle', 'Verified visits', 'Repeat visits', 'Unverified visits', 'Officers active', 'Last visit'];
        $line = fn ($r, $i) => [$i, $r['name'], $r['parent'] ?? '', $r['schools'], $r['visited'], $pct($r['visit_pct']), $r['band']['label'],
            $r['inspected'], $pct($r['insp_pct']), $r['not_visited'], $r['due'], $r['visits'], $r['repeat'], $r['unverified'], $r['officers'],
            $r['last'] ? Carbon::parse($r['last'])->format('d-m-Y') : ''];

        $sheets = [[new ArrayExport(['Item', 'Value'], [
            ['Area', $this->areaTitle($f)],
            ['Coverage period', $period->label().' ('.$period->from->format('d-m-Y').' to '.$period->to->format('d-m-Y').')'],
            ['Configured period type', $setting->periodLabel().($setting->district_id ? ' (district setting)' : ' (state default)')],
            ['Inspection cycle', $setting->cycleLabel().': '.$cycle->from->format('d-m-Y').' to '.$cycle->to->format('d-m-Y')],
            ['Verification radius', $this->radius().' m'],
            ['Assigned schools', $s['schools']],
            ['Unique schools visited (verified)', $s['visited']],
            ['Visit coverage %', $pct($s['visit_pct'])],
            ['Schools inspected in cycle', $s['inspected']],
            ['Inspection coverage %', $pct($s['insp_pct'])],
            ['Not visited', $s['not_visited']],
            ['Verified visits / repeat / unverified', $s['visits'].' / '.$s['repeat'].' / '.$s['unverified']],
            ['Officers active', $s['officers']],
            ['Visit Coverage', 'At least one verified visit (location within the radius of the school) in the period.'],
            ['Inspection Coverage', 'A formal inspection submitted within the prescribed inspection cycle.'],
        ]), 'Summary']];

        foreach ($this->levels($user, $f) as $level) {
            $rows = $this->areas($user, $f, $level);
            $data = $rows->values()->map(fn ($r, $i) => $line($r, $i + 1))->all();
            $data[] = $line($this->finish(self::sum($this->data($user, $f)['clusters'])) + ['name' => 'Total', 'parent' => ''], '');
            $sheets[] = [new ArrayExport($heads, $data), self::LEVELS[$level]];
        }

        $schoolRows = $this->schoolsForExport($user, $f);
        $sheets[] = [new ArrayExport(['UDISE', 'School', 'Cluster', 'Block', 'District', 'Unverified visits'],
            $schoolRows->where('verified', 0)->map(fn ($r) => [$r['udise'], $r['name'], $r['cluster'], $r['block'], $r['district'], $r['unverified']])->values()->all()), 'Schools not visited'];
        $sheets[] = [new ArrayExport(['UDISE', 'School', 'Cluster', 'Block', 'District', 'Verified visits in period'],
            $schoolRows->where('inspected', false)->map(fn ($r) => [$r['udise'], $r['name'], $r['cluster'], $r['block'], $r['district'], $r['verified']])->values()->all()), 'Due in cycle'];

        return new SchoolTemplateExport($sheets);
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
            ->select('schools.id', 'schools.udise_code', 'schools.name', 'clusters.name as cluster', 'blocks.name as block', 'districts.name as district')
            ->orderBy('districts.name')->orderBy('blocks.name')->orderBy('clusters.name')->orderBy('schools.name')
            ->chunk(2000, function ($rows) use (&$out, $data) {
                foreach ($rows as $r) {
                    $v = $data['schools'][$r->id] ?? null;
                    $out->push(['udise' => $r->udise_code, 'name' => $r->name, 'cluster' => $r->cluster, 'block' => $r->block, 'district' => $r->district,
                        'verified' => $v['verified'] ?? 0, 'unverified' => $v['unverified'] ?? 0, 'inspected' => isset($data['cycle'][$r->id])]);
                }
            });

        return $out;
    }
}
