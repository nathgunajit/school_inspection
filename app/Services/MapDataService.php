<?php

namespace App\Services;

use App\Enums\InspectionStatus;
use App\Enums\UserStatus;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\Inspection;
use App\Models\InspectionRating;
use App\Models\User;
use App\Rating\RatingCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data for the interactive Map View (New Module 1).
 *
 * Hierarchy: State → District → Block → Cluster → School. Every query starts from
 * JurisdictionService, so an officer only ever sees the schools and officers of their own area.
 *
 * School status in the period (default: this month):
 *  inspected   = a submitted inspection dated in the period
 *  in_progress = an open draft or an unlocked inspection (any date)
 *  pending     = neither of the above
 */
class MapDataService
{
    public const LEVELS = ['district', 'block', 'cluster'];

    public function __construct(private JurisdictionService $j) {}

    /** The level and parent the map opens at for this user. */
    public function startView(User $user): array
    {
        $jur = $this->j->current($user);
        if ($user->level->isStateWide() || ! $jur) {
            return ['level' => 'district', 'parent' => null];
        }

        return match ($user->level->value) {
            'district' => ['level' => 'block', 'parent' => $jur->district_id],
            'block' => ['level' => 'cluster', 'parent' => $jur->block_id],
            default => ['level' => 'school', 'parent' => $jur->cluster_id],
        };
    }

    /**
     * Can the user open the area $type #$id? True when the area and the user's jurisdiction
     * overlap: the area lies inside it, or contains it (needed for the breadcrumb).
     */
    public function canSeeArea(User $user, string $type, ?int $id): bool
    {
        if ($user->level->isStateWide()) {
            return true;
        }
        $jur = $this->j->current($user);
        if (! $jur || $id === null) {
            return false;
        }
        $chain = $this->chain($type, $id);
        if (! $chain) {
            return false;
        }
        foreach (['district_id', 'block_id', 'cluster_id'] as $col) {
            if ($chain[$col] !== null && $jur->$col !== null && (int) $chain[$col] !== (int) $jur->$col) {
                return false;
            }
        }

        return true;
    }

    /** Ancestry of an area: [district_id, block_id, cluster_id] (null below the area's own level). */
    public function chain(string $type, int $id): ?array
    {
        // 26-11-2026: one map request asks for the same chain up to five times (access check, breadcrumb, outline,
        // officers, offices); it is read once per request
        return \App\Support\RequestMemo::remember("map.chain.{$type}.{$id}", fn () => match ($type) {
            'district' => District::whereKey($id)->exists() ? ['district_id' => $id, 'block_id' => null, 'cluster_id' => null] : null,
            'block' => ($b = Block::find($id)) ? ['district_id' => $b->district_id, 'block_id' => $b->id, 'cluster_id' => null] : null,
            'cluster' => ($c = Cluster::with('block')->find($id)) ? ['district_id' => $c->block->district_id, 'block_id' => $c->block_id, 'cluster_id' => $c->id] : null,
            default => null,
        });
    }

    /** Breadcrumb from the state down to (and including) the given parent area. */
    public function breadcrumb(string $level, ?int $parent): array
    {
        $crumbs = [['level' => 'district', 'parent' => null, 'name' => 'Assam']];
        if ($parent === null) {
            return $crumbs;
        }
        $parentType = $this->parentType($level);
        $chain = $this->chain($parentType, $parent);
        if ($chain['district_id']) {
            $crumbs[] = ['level' => 'block', 'parent' => $chain['district_id'], 'name' => District::find($chain['district_id'])?->label];
        }
        if ($chain['block_id']) {
            $crumbs[] = ['level' => 'cluster', 'parent' => $chain['block_id'], 'name' => Block::find($chain['block_id'])?->label];
        }
        if ($chain['cluster_id']) {
            $crumbs[] = ['level' => 'school', 'parent' => $chain['cluster_id'], 'name' => Cluster::find($chain['cluster_id'])?->label];
        }

        return $crumbs;
    }

    /** V6 Module 7: URL of the outline of the district that contains the area being viewed (null at state level). */
    public function outlineUrl(string $level, ?int $parent): ?string
    {
        $type = $this->parentType($level);
        $chain = $type && $parent !== null ? $this->chain($type, $parent) : null;

        return app(DistrictBoundaryService::class)->urlForId($chain['district_id'] ?? null);
    }

    public function parentType(string $level): ?string
    {
        return ['district' => null, 'block' => 'district', 'cluster' => 'block', 'school' => 'cluster'][$level] ?? null;
    }

    /**
     * One row per District / Block / Cluster under $parent, with school status counts,
     * coverage and officer counts.
     */
    public function areas(User $user, string $level, ?int $parent, array $filters): array
    {
        return $this->remember($user, ['areas', $level, $parent, $filters], function () use ($user, $level, $parent, $filters) {
            $col = $level.'_id';
            $schools = $this->schoolsQuery($user, $level, $parent, $filters);
            [$insp, $prog, $ib, $pb] = $this->statusSql($filters, $user);

            $rows = $schools->toBase()
                ->selectRaw("schools.$col as area_id, count(*) as schools,
                    sum(case when $insp then 1 else 0 end) as inspected,
                    sum(case when not ($insp) and $prog then 1 else 0 end) as in_progress,
                    sum(case when schools.latitude is null or schools.longitude is null then 1 else 0 end) as no_gps,
                    avg(schools.latitude) as lat, avg(schools.longitude) as lng", array_merge($ib, $ib, $pb))
                ->groupBy("schools.$col")->get()->keyBy('area_id');

            $model = ['district' => District::class, 'block' => Block::class, 'cluster' => Cluster::class][$level];
            $fk = ['district' => 'state_id', 'block' => 'district_id', 'cluster' => 'block_id'][$level];
            $areas = $model::query()->where('is_active', true)
                ->when($parent !== null, fn ($q) => $q->where($fk, $parent))
                ->whereIn('id', $rows->keys()->merge($this->visibleAreaIds($user, $level)))
                ->orderBy('name')->get(['id', 'code', 'name', 'latitude', 'longitude', ...($level === 'district' ? ['boundary_name'] : [])]);
            $boundaries = app(DistrictBoundaryService::class);

            $officers = $this->officerCounts($user, $level, $areas->pluck('id')->all());
            $ratings = $this->areaRatings($col, $areas->pluck('id')->all(), $filters, $user);

            return $areas->map(function ($a) use ($rows, $officers, $level, $boundaries, $ratings) {
                $r = $rows->get($a->id);
                $total = (int) ($r->schools ?? 0);
                $inspected = (int) ($r->inspected ?? 0);
                $progress = (int) ($r->in_progress ?? 0);

                return [
                    'id' => $a->id,
                    'code' => $a->code,
                    'name' => $a->name, // matched to boundary names in map.js
                    'label' => $a->label,
                    // the centre of the area's schools; a district / block point is its office (offices()), used only when there are no schools with GPS
                    'lat' => $r?->lat !== null ? round((float) $r->lat, 6) : $a->latitude,
                    'lng' => $r?->lng !== null ? round((float) $r->lng, 6) : $a->longitude,
                    'schools' => $total,
                    'inspected' => $inspected,
                    'in_progress' => $progress,
                    'pending' => $total - $inspected - $progress,
                    'no_gps' => (int) ($r->no_gps ?? 0),
                    'coverage' => $total ? round($inspected * 100 / $total, 1) : 0.0,
                    'officers' => $officers[$a->id] ?? ['total' => 0, 'district' => 0, 'block' => 0, 'cluster' => 0],
                    // V6 Module 7: the KML outline this District Master row is drawn with
                    'boundary' => $level === 'district' ? $boundaries->nameFor($a) : null,
                    // V6 Module 9: average rating of the inspections submitted in the period
                    'rating' => $ratings[$a->id] ?? null,
                ];
            })->values()->all();
        });
    }

    /**
     * V6 Module 9: average rating per area of the inspections submitted in the period (and by the
     * filtered officer), with the band of the active rating scheme. Areas without a rated inspection are left out.
     *
     * @return array<int, array{avg: float, count: int, band: ?string, color: ?string}>
     */
    private function areaRatings(string $col, array $areaIds, array $filters, ?User $user = null): array
    {
        if (! $areaIds) {
            return [];
        }
        $bands = app(RatingService::class)->activeScheme()->bands;

        return InspectionRating::query()
            ->join('inspections', 'inspections.id', '=', 'inspection_ratings.inspection_id')
            ->join('schools', 'schools.id', '=', 'inspections.school_id')
            ->where('inspections.status', InspectionStatus::Submitted)
            ->whereBetween('inspections.inspection_date', [$filters['from'], $filters['to']])
            ->when($filters['officer_id'] ?? null, fn ($q, $v) => $q->where('inspections.officer_id', $v))
            ->tap(fn ($q) => \App\Support\InspectionVisibility::apply($q, $user)) // 25-11-2026: own + lower levels
            ->whereNotNull('inspection_ratings.score_percent')
            ->whereIn("schools.$col", $areaIds)
            ->groupBy("schools.$col")
            ->selectRaw("schools.$col as area_id, avg(inspection_ratings.score_percent) as avg_score, count(*) as n")
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->area_id => ['avg' => round((float) $r->avg_score, 1), 'count' => (int) $r->n]
                + RatingCalculator::band(round((float) $r->avg_score, 1), $bands)])
            ->all();
    }

    /** Schools of one cluster (or matching a search) with their status and last inspection. */
    public function schools(User $user, ?int $clusterId, array $filters, ?string $search = null): array
    {
        return $this->remember($user, ['schools', $clusterId, $filters, $search], function () use ($user, $clusterId, $filters, $search) {
            [$insp, $prog, $ib, $pb] = $this->statusSql($filters, $user);
            $q = $this->schoolsQuery($user, 'school', $clusterId, $filters)
                ->selectRaw("schools.id, schools.udise_code, schools.name, schools.address, schools.latitude, schools.longitude, schools.school_type, schools.area_type,
                    schools.district_id, schools.block_id, schools.cluster_id,
                    case when $insp then 'inspected' when $prog then 'in_progress' else 'pending' end as map_status", array_merge($ib, $pb))
                ->with(['cluster:id,code,name', 'block:id,code,name', 'district:id,code,name'])
                ->orderBy('schools.name')
                ->limit($search ? 50 : 2000);

            // 26-11-2026: a name search uses the FULLTEXT word-start match first, the exact search when it finds nothing
            $schools = $search ? \App\Support\SchoolSearch::live($q, $search, fn ($w) => $w->get()) : $q->get();

            // last submitted inspection of each school (any date), with its officer
            // 25-11-2026: the last one the user may see (own + lower levels)
            $last = Inspection::query()->with(['officer:id,name', 'rating:id,inspection_id,score_percent,band,color'])
                ->whereIn('id', \App\Support\InspectionVisibility::apply(Inspection::query(), $user)->selectRaw('max(id)')->whereIn('school_id', $schools->pluck('id'))
                    ->where('status', InspectionStatus::Submitted)->groupBy('school_id'))
                ->get(['id', 'school_id', 'inspection_no', 'inspection_date', 'officer_id'])->keyBy('school_id');

            return $schools->map(fn ($s) => [
                'id' => $s->id,
                'key' => $s->getRouteKey(), // V6 Module 4: encrypted token for URLs
                'udise' => $s->udise_code,
                'name' => $s->name,
                'address' => $s->address,
                'info' => collect([$s->school_type_label, $s->area_type_label])->filter()->implode(' · ') ?: null, // UDISE+ details
                'lat' => $s->latitude,
                'lng' => $s->longitude,
                'status' => $s->map_status,
                'area' => collect([$s->cluster?->label, $s->block?->label, $s->district?->label])->filter()->implode(' · '),
                'cluster_id' => $s->cluster_id,
                'block_id' => $s->block_id, // 23-11-2026: the Block / District office distances in the popup
                'district_id' => $s->district_id,
                'last' => ($l = $last->get($s->id)) ? [
                    'id' => $l->id, 'key' => $l->getRouteKey(), 'no' => $l->inspection_no, 'date' => $l->inspection_date?->format('d-m-Y'), 'officer' => $l->officer?->name,
                ] : null,
                // V6 Module 9: the rating of the latest submitted inspection
                'rating' => ($l = $last->get($s->id)) && $l->rating?->score_percent !== null ? [
                    'score' => (float) $l->rating->score_percent, 'band' => $l->rating->band, 'color' => $l->rating->color,
                ] : null,
            ])->values()->all();
        });
    }

    /**
     * 23-11-2026: District and Block offices for the map (their latitude / longitude in the Location Master).
     *  state view (district level)  → every district office
     *  inside a district (block)    → that district office and its block offices
     *  inside a block / a cluster   → the block office and its district office
     *  a school search              → the offices of the schools found
     * Outside the State, only the user's own district / block (and the district that contains them) is listed.
     * Active offices with coordinates only.
     *
     * @return list<array{type: string, id: int, name: string, label: string, lat: float, lng: float, address: ?string}>
     */
    public function offices(User $user, string $level, ?int $parent, array $schoolRows = []): array
    {
        $districtIds = $blockIds = null; // null = every one
        $blocksToo = true;
        if ($level === 'district') {
            $blocksToo = false;
        } elseif ($level === 'block' && $parent !== null) {
            $districtIds = [$parent];
            $blockIds = Block::where('district_id', $parent)->pluck('id')->all();
        } elseif (($level === 'cluster' || $level === 'school') && $parent !== null) {
            $chain = $this->chain($level === 'cluster' ? 'block' : 'cluster', $parent);
            $districtIds = [$chain['district_id'] ?? 0];
            $blockIds = [$chain['block_id'] ?? 0];
        } else {
            $districtIds = array_values(array_unique(array_filter(array_column($schoolRows, 'district_id'))));
            $blockIds = array_values(array_unique(array_filter(array_column($schoolRows, 'block_id'))));
        }

        return $this->remember($user, ['offices', $level, $parent, $districtIds, $blockIds], function () use ($user, $districtIds, $blockIds, $blocksToo) {
            $jur = $user->level->isStateWide() ? null : $this->j->current($user);
            $scope = fn ($q, string $col) => $q->where('is_active', true)->whereNotNull('latitude')->whereNotNull('longitude')
                ->when($jur && $jur->$col, fn ($w) => $w->whereKey($jur->$col));
            $row = fn (string $type, $m) => [
                'type' => $type, 'id' => $m->id, 'name' => $m->name, 'label' => $m->label,
                'lat' => (float) $m->latitude, 'lng' => (float) $m->longitude, 'address' => $m->office_address,
            ];

            $districts = $scope(District::query(), 'district_id')->when($districtIds !== null, fn ($q) => $q->whereIn('id', $districtIds))
                ->orderBy('name')->get(['id', 'code', 'name', 'latitude', 'longitude', 'office_address'])->map(fn ($d) => $row('district', $d));
            $blocks = $blocksToo
                ? $scope(Block::query(), 'block_id')->when($blockIds !== null, fn ($q) => $q->whereIn('id', $blockIds))
                    ->when($jur && ! $jur->block_id && $jur->district_id, fn ($q) => $q->where('district_id', $jur->district_id))
                    ->orderBy('name')->limit(500)->get(['id', 'code', 'name', 'latitude', 'longitude', 'office_address'])->map(fn ($b) => $row('block', $b))
                : collect();

            return $districts->concat($blocks)->values()->all();
        });
    }

    /** Officers (active, with a current posting) inside the user's jurisdiction and the given area. */
    public function officers(User $user, string $level, ?int $parent): array
    {
        $parentType = $this->parentType($level);

        return $this->j->users($user)
            ->where('status', UserStatus::Active)
            ->where('level', '!=', 'state_admin')
            ->whereHas('currentJurisdiction', fn ($w) => $parentType && $parent !== null ? $w->where($parentType.'_id', $parent) : $w)
            ->with(['currentJurisdiction.state:id,name', 'currentJurisdiction.district:id,name,code', 'currentJurisdiction.block:id,name,code', 'currentJurisdiction.cluster:id,name,code', 'designation:id,code,label'])
            ->orderBy('name')->limit(500)->get(['id', 'name', 'level', 'designation_id', 'designation_other'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'level' => $u->level->label(),
                'designation' => $u->designation_label,
                'area' => $u->currentJurisdiction?->label() ?: 'State',
            ])->all();
    }

    /** Totals over a list of area rows (the stat cards). */
    public function totals(array $rows, int $officers): array
    {
        $sum = fn ($k) => array_sum(array_column($rows, $k));
        $schools = $sum('schools');

        return [
            'officers' => $officers,
            'schools' => $schools,
            'inspected' => $sum('inspected'),
            'in_progress' => $sum('in_progress'),
            'pending' => $sum('pending'),
            'no_gps' => $sum('no_gps'),
            'coverage' => $schools ? round($sum('inspected') * 100 / $schools, 1) : 0.0,
        ];
    }

    /** Totals for a list of school rows. */
    public function schoolTotals(array $schools, int $officers): array
    {
        $count = fn ($status) => count(array_filter($schools, fn ($s) => $s['status'] === $status));
        $total = count($schools);

        return [
            'officers' => $officers,
            'schools' => $total,
            'inspected' => $count('inspected'),
            'in_progress' => $count('in_progress'),
            'pending' => $count('pending'),
            'no_gps' => count(array_filter($schools, fn ($s) => $s['lat'] === null || $s['lng'] === null)),
            'coverage' => $total ? round($count('inspected') * 100 / $total, 1) : 0.0,
        ];
    }

    // ------------------------------------------------------------------

    /** Active schools in the user's jurisdiction, under the parent of $level, optionally only those an officer inspected. */
    private function schoolsQuery(User $user, string $level, ?int $parent, array $filters): Builder
    {
        $parentType = $this->parentType($level);

        return $this->j->schools($user)
            ->where('schools.status', 'active')
            ->when($parentType && $parent !== null, fn ($q) => $q->where("schools.{$parentType}_id", $parent))
            ->when($filters['officer_id'] ?? null, fn ($q, $officer) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('inspections')
                ->whereColumn('inspections.school_id', 'schools.id')
                ->where('inspections.officer_id', $officer)
                ->tap(fn ($w) => \App\Support\InspectionVisibility::apply($w, $user))
                ->where('inspections.status', '!=', InspectionStatus::Cancelled->value)
                ->where('inspections.inspection_date', '>=', $filters['from'])
                ->where('inspections.inspection_date', '<=', $filters['to'])));
    }

    /** SQL for "inspected in the period" and "in progress", with their bindings (one list per occurrence). */
    private function statusSql(array $filters, ?User $user = null): array
    {
        $officer = $filters['officer_id'] ?? null;
        // 25-11-2026: only inspections the user may see (own + lower levels)
        $by = ($officer ? ' and i.officer_id = ?' : '').\App\Support\InspectionVisibility::sql($user, 'i');
        $insp = "exists (select 1 from inspections i where i.school_id = schools.id and i.status = 'submitted'
            and i.inspection_date >= ? and i.inspection_date <= ?$by)";
        $prog = "exists (select 1 from inspections i where i.school_id = schools.id and i.status in ('draft', 'unlocked')$by)";
        $inspBind = $officer ? [$filters['from'], $filters['to'], $officer] : [$filters['from'], $filters['to']];
        $progBind = $officer ? [$officer] : [];

        return [$insp, $prog, $inspBind, $progBind];
    }

    /** The user's own area at this level is always listed, even when it has no active schools yet. */
    private function visibleAreaIds(User $user, string $level): array
    {
        $jur = $this->j->current($user);

        return $jur && $jur->{$level.'_id'} ? [$jur->{$level.'_id'}] : [];
    }

    /** Officers posted in each area (any level at or below it), split by level. */
    private function officerCounts(User $user, string $level, array $areaIds): array
    {
        if (! $areaIds) {
            return [];
        }
        $col = $level.'_id';
        $rows = DB::table('user_jurisdictions as uj')
            ->join('users as u', 'u.id', '=', 'uj.user_id')
            ->whereNull('uj.end_date')
            ->where('u.status', UserStatus::Active->value)
            ->whereNull('u.deleted_at')
            ->whereIn("uj.$col", $areaIds)
            ->whereIn('u.id', $this->j->users($user)->select('users.id'))
            ->groupBy("uj.$col", 'u.level')
            ->selectRaw("uj.$col as area_id, u.level, count(*) as n")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->area_id] ??= ['total' => 0, 'district' => 0, 'block' => 0, 'cluster' => 0];
            $out[$r->area_id]['total'] += $r->n;
            if (isset($out[$r->area_id][$r->level])) {
                $out[$r->area_id][$r->level] += $r->n;
            }
        }

        return $out;
    }

    private function remember(User $user, array $key, \Closure $fn): array
    {
        $seconds = (int) config('inspection.map.cache_seconds', 60);
        if ($seconds <= 0) {
            return $fn();
        }

        return Cache::remember('map:'.$user->id.':'.md5(json_encode($key)), $seconds, $fn);
    }
}
