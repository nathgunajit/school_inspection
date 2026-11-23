<?php

namespace App\Services;

use App\Enums\InspectionStatus;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\Inspection;
use App\Models\InspectionRating;
use App\Models\RatingScheme;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * V6 Module 10: the rating report. Every figure comes from rated, submitted inspections inside the
 * user's jurisdiction (JurisdictionService) and the period and filters chosen.
 *
 * - summary: rated inspections, schools, average score, band distribution, section averages
 * - areas: one row per district / block / cluster below the current scope
 * - schools: each school's latest rating in the period, the rating before it and the change
 */
class RatingReportService
{
    public const SORTS = ['score_desc' => 'Highest score first', 'score_asc' => 'Lowest score first', 'change_desc' => 'Most improved', 'change_asc' => 'Most declined', 'name' => 'School name'];

    public function __construct(private JurisdictionService $j, private RatingService $ratings) {}

    /** Validated filters with defaults (the last three months, newest scheme bands). */
    public function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'district_id' => ['nullable', 'integer'],
            'block_id' => ['nullable', 'integer'],
            'cluster_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'band' => ['nullable', 'string', 'max:40'],
            'version' => ['nullable', 'integer'],
            'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORTS))],
        ]);

        return [
            'from' => $request->date('from')?->toDateString() ?? now()->subMonths(2)->startOfMonth()->toDateString(),
            'to' => $request->date('to')?->toDateString() ?? today()->toDateString(),
            'district_id' => isset($data['district_id']) ? (int) $data['district_id'] : null,
            'block_id' => isset($data['block_id']) ? (int) $data['block_id'] : null,
            'cluster_id' => isset($data['cluster_id']) ? (int) $data['cluster_id'] : null,
            'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : null,
            'band' => $data['band'] ?? null,
            'version' => isset($data['version']) ? (int) $data['version'] : null,
            'sort' => $data['sort'] ?? 'score_desc',
        ];
    }

    /** Rated, submitted inspections in the user's jurisdiction matching the filters, joined with their rating. */
    public function base(User $user, array $f): Builder
    {
        return $this->j->inspections($user)
            ->join('inspection_ratings as ir', 'ir.inspection_id', '=', 'inspections.id')
            ->where('inspections.status', InspectionStatus::Submitted)
            ->whereNotNull('ir.score_percent')
            ->where('inspections.inspection_date', '>=', $f['from'])
            ->where('inspections.inspection_date', '<=', $f['to'])
            ->when($f['district_id'], fn ($q, $v) => $q->where('inspections.district_id', $v))
            ->when($f['block_id'], fn ($q, $v) => $q->where('inspections.block_id', $v))
            ->when($f['cluster_id'], fn ($q, $v) => $q->where('inspections.cluster_id', $v))
            ->when($f['category_id'], fn ($q, $v) => $q->whereIn('inspections.school_id', School::query()->select('id')->where('category_id', $v)))
            ->when($f['band'], fn ($q, $v) => $q->where('ir.band', $v))
            ->when($f['version'], fn ($q, $v) => $q->whereIn('ir.scheme_id', RatingScheme::query()->select('id')->where('version', $v)));
    }

    /** The bands of the active scheme, best first. */
    public function bands(): array
    {
        $bands = $this->ratings->activeScheme()->bands;
        usort($bands, fn ($a, $b) => $b['min'] <=> $a['min']);

        return $bands;
    }

    public function summary(User $user, array $f): array
    {
        $base = $this->base($user, $f);
        $totals = (clone $base)->toBase()->selectRaw('count(*) as n, count(distinct inspections.school_id) as schools, avg(ir.score_percent) as avg, min(ir.score_percent) as low, max(ir.score_percent) as high')->first();
        $byBand = (clone $base)->toBase()->selectRaw('ir.band, ir.color, count(*) as n')->groupBy('ir.band', 'ir.color')->get();

        // every band of the active scheme (zero when empty), then any band only found in older scheme versions
        $bands = collect($this->bands())->map(fn ($b) => ['label' => $b['label'], 'color' => $b['color'], 'min' => $b['min'],
            'count' => (int) $byBand->where('band', $b['label'])->sum('n')]);
        foreach ($byBand->whereNotIn('band', $bands->pluck('label')) as $extra) {
            $bands->push(['label' => $extra->band, 'color' => $extra->color, 'min' => null, 'count' => (int) $extra->n]);
        }

        $avg = $totals->n ? round((float) $totals->avg, 1) : null;

        return [
            'rated' => (int) $totals->n,
            'schools' => (int) $totals->schools,
            'avg' => $avg,
            'avg_band' => $avg !== null ? \App\Rating\RatingCalculator::band($avg, $this->bands()) : ['band' => null, 'color' => null],
            'low' => $totals->n ? round((float) $totals->low, 1) : null,
            'high' => $totals->n ? round((float) $totals->high, 1) : null,
            'bands' => $bands->values()->all(),
            'sections' => $this->sections($base),
        ];
    }

    /** Average percent of every questionnaire section over the rated inspections (sections Not Applicable left out). */
    private function sections(Builder $base): array
    {
        $acc = [];
        (clone $base)->toBase()->select('ir.sections')->orderBy('inspections.id')->chunk(1000, function ($rows) use (&$acc) {
            foreach ($rows as $r) {
                foreach (json_decode((string) $r->sections, true) ?: [] as $key => $s) {
                    $acc[$key] ??= ['title' => $s['title'] ?? $key, 'sum' => 0.0, 'n' => 0];
                    if (($s['percent'] ?? null) !== null) {
                        $acc[$key]['sum'] += (float) $s['percent'];
                        $acc[$key]['n']++;
                    }
                }
            }
        });

        $bands = $this->bands();

        // Modification V8 item 5: each section average also carries its band, so the report can colour it
        return collect($acc)->map(function ($s, $key) use ($bands) {
            $avg = $s['n'] ? round($s['sum'] / $s['n'], 1) : null;

            return ['key' => $key, 'title' => $s['title'], 'count' => $s['n'], 'avg' => $avg] + \App\Rating\RatingCalculator::band($avg, $bands);
        })->values()->all();
    }

    /** The level shown in the area breakdown: one below the deepest area selected (or the user's own level). */
    public function areaLevel(User $user, array $f): ?string
    {
        $jur = $this->j->current($user);
        if ($f['cluster_id'] || $user->level->value === 'cluster') {
            return null;
        }
        if ($f['block_id'] || $user->level->value === 'block') {
            return 'cluster';
        }
        if ($f['district_id'] || $user->level->value === 'district' || (! $user->level->isStateWide() && $jur?->district_id)) {
            return 'block';
        }

        return 'district';
    }

    /** One row per area: rated inspections, schools, average, and a count per band. */
    public function areas(User $user, array $f): array
    {
        $level = $this->areaLevel($user, $f);
        if (! $level) {
            return [];
        }
        $col = "inspections.{$level}_id";
        $rows = (clone $this->base($user, $f))->toBase()
            ->selectRaw("$col as area_id, count(*) as n, count(distinct inspections.school_id) as schools, avg(ir.score_percent) as avg")
            ->groupBy($col)->get()->keyBy('area_id');
        $bandRows = (clone $this->base($user, $f))->toBase()->selectRaw("$col as area_id, ir.band, count(*) as n")->groupBy($col, 'ir.band')->get()->groupBy('area_id');
        $model = ['district' => District::class, 'block' => Block::class, 'cluster' => Cluster::class][$level];
        $names = $model::whereIn('id', $rows->keys())->get(['id', 'code', 'name'])->mapWithKeys(fn ($m) => [$m->id => $m->label]);
        $bands = $this->bands();

        return $rows->map(fn ($r) => [
            'level' => $level,
            'id' => (int) $r->area_id,
            'name' => $names[$r->area_id] ?? '—',
            'rated' => (int) $r->n,
            'schools' => (int) $r->schools,
            'avg' => round((float) $r->avg, 1),
            'bands' => collect($bandRows->get($r->area_id, collect()))->pluck('n', 'band')->map(fn ($n) => (int) $n)->all(),
        ] + \App\Rating\RatingCalculator::band(round((float) $r->avg, 1), $bands))
            ->sortByDesc('avg')->values()->all();
    }

    /**
     * Each school's latest rated inspection in the filtered set, with the rating before it (any earlier date,
     * same jurisdiction) and the change. Returns a collection; the caller sorts / pages it.
     */
    public function schools(User $user, array $f): Collection
    {
        $latestIds = (clone $this->base($user, $f))->toBase()->selectRaw('max(inspections.id) as id')->groupBy('inspections.school_id')->pluck('id');
        $latest = Inspection::query()->whereIn('id', $latestIds)
            ->with(['school:id,name,udise_code,category_id', 'school.category:id,label', 'district:id,name', 'block:id,name', 'cluster:id,name', 'officer:id,name', 'rating'])
            ->get();

        // earlier ratings of the same schools, newest first, to find the previous one
        $earlier = $this->j->inspections($user)
            ->join('inspection_ratings as ir', 'ir.inspection_id', '=', 'inspections.id')
            ->where('inspections.status', InspectionStatus::Submitted)->whereNotNull('ir.score_percent')
            ->whereIn('inspections.school_id', $latest->pluck('school_id'))
            ->orderByDesc('inspections.inspection_date')->orderByDesc('inspections.id')
            ->toBase()->get(['inspections.id', 'inspections.school_id', 'inspections.inspection_date', 'ir.score_percent', 'ir.band', 'ir.color'])
            ->groupBy('school_id');

        return $latest->map(function (Inspection $i) use ($earlier) {
            $prev = collect($earlier->get($i->school_id, []))->first(fn ($e) => $e->id !== $i->id
                && ((string) $e->inspection_date < $i->inspection_date->toDateString()
                    || ((string) $e->inspection_date === $i->inspection_date->toDateString() && $e->id < $i->id)));
            $score = (float) $i->rating->score_percent;

            return [
                'inspection' => $i,
                'school' => $i->school,
                'score' => $score,
                'band' => $i->rating->band,
                'color' => $i->rating->color,
                'previous' => $prev ? ['score' => (float) $prev->score_percent, 'band' => $prev->band, 'color' => $prev->color, 'date' => (string) $prev->inspection_date] : null,
                'change' => $prev ? round($score - (float) $prev->score_percent, 1) : null,
            ];
        });
    }

    public function sortSchools(Collection $rows, string $sort): Collection
    {
        return (match ($sort) {
            'score_asc' => $rows->sortBy('score'),
            'change_desc' => $rows->sortByDesc(fn ($r) => $r['change'] ?? -INF),
            'change_asc' => $rows->sortBy(fn ($r) => $r['change'] ?? INF),
            'name' => $rows->sortBy(fn ($r) => mb_strtolower($r['school']->name)),
            default => $rows->sortByDesc('score'),
        })->values();
    }

    public function paginate(Collection $rows, Request $request): LengthAwarePaginator
    {
        $per = (int) config('inspection.per_page');
        $page = max(1, (int) $request->input('page', 1));

        return (new LengthAwarePaginator($rows->forPage($page, $per)->values(), $rows->count(), $per, $page,
            ['path' => $request->url(), 'pageName' => 'page']))->withQueryString();
    }

    /** Filter lists, limited to the user's jurisdiction. */
    public function options(User $user, array $f): array
    {
        $jur = $this->j->current($user);
        $districtId = $f['district_id'] ?: $jur?->district_id;
        $blockId = $f['block_id'] ?: $jur?->block_id;

        return [
            'districts' => $user->level->isStateWide() ? District::orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'blocks' => $districtId && in_array($user->level->value, ['state_admin', 'state', 'district'], true)
                ? Block::where('district_id', $districtId)->orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'clusters' => $blockId && $user->level->value !== 'cluster' ? Cluster::where('block_id', $blockId)->orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'categories' => \App\Models\MasterValue::where('type', 'category')->orderBy('sort')->get(['id', 'label']),
            'versions' => RatingScheme::orderByDesc('version')->pluck('version'),
            'bandList' => $this->bands(),
        ];
    }

    /** The rated inspections behind the report, for the Excel export. */
    public function exportRows(User $user, array $f): Collection
    {
        return Inspection::query()->whereIn('id', (clone $this->base($user, $f))->toBase()->select('inspections.id'))
            ->with(['school:id,name,udise_code,category_id', 'school.category:id,label', 'district:id,name', 'block:id,name', 'cluster:id,name', 'officer:id,name', 'rating.scheme:id,version'])
            ->orderBy('inspection_date')->orderBy('id')->limit(20000)->get();
    }
}
