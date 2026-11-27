<?php

namespace App\Services;

use App\Models\District;
use App\Models\User;
use App\Support\CoveragePeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * 27-11-2026: District Ranking report. Two separate rankings of the districts for one period:
 *  - Coverage: by Inspection Coverage % (schools with a submitted inspection in the cycle), Visit Coverage % beside it;
 *  - Rating: by the average rating % of the submitted, rated inspections in the period (State Admin only).
 * Ties share a rank (1, 2, 2, 4). Districts without schools / ratings are listed last without a rank.
 * All figures come from CoverageReportService and RatingReportService, so they match those reports.
 */
class DistrictRankingService
{
    public const COVERAGE_HEADS = ['Rank', 'District', 'Schools', 'Inspected', 'Inspection coverage %', 'Visited', 'Visit coverage %'];

    public const RATING_HEADS = ['Rank', 'District', 'Rated inspections', 'Schools rated', 'Average rating %', 'Band'];

    public function __construct(private CoverageReportService $coverage, private RatingReportService $ratings) {}

    /** The coverage filters (period, category), always state-wide. */
    public function filters(Request $request, User $user): array
    {
        return ['district_id' => null, 'block_id' => null, 'cluster_id' => null, 'level' => 'district']
            + $this->coverage->filters($request, $user);
    }

    public function period(array $f): CoveragePeriod
    {
        return $this->coverage->period($f);
    }

    public function cycle(array $f): CoveragePeriod
    {
        return $this->coverage->cycle($f);
    }

    /** May this user see the Rating ranking? The same rule as the Rating Report. */
    public static function showsRating(User $user): bool
    {
        return $user->can('state_admin');
    }

    /** Districts ranked by Inspection Coverage %. */
    public function coverageRanking(User $user, array $f): Collection
    {
        $rows = $this->coverage->areas($user, $f, 'district')->map(fn ($r) => [
            'id' => $r['id'], 'name' => $r['name'], 'schools' => $r['schools'], 'inspected' => $r['inspected'], 'pct' => $r['insp_pct'],
            'visited' => $r['visited'], 'visit_pct' => $r['visit_pct'], 'tone' => $r['insp_band']['tone'] ?? 'secondary', 'band' => $r['insp_band']['label'] ?? '',
        ]);

        return self::rank($rows, 'pct', 'inspected');
    }

    /** Districts ranked by the average rating %; every active district is listed (unrated last). */
    public function ratingRanking(User $user, array $f): Collection
    {
        $period = $this->period($f);
        $rf = ['from' => $period->fromDate(), 'to' => $period->toDate(), 'district_id' => null, 'block_id' => null, 'cluster_id' => null,
            'category_id' => $f['category_id'], 'band' => null, 'version' => null, 'sort' => 'score_desc'];
        $rated = collect($this->ratings->areas($user, $rf))->keyBy('id');
        $rows = District::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])->map(function ($d) use ($rated) {
            $r = $rated->get($d->id);

            return ['id' => $d->id, 'name' => $d->label, 'rated' => $r['rated'] ?? 0, 'schools' => $r['schools'] ?? 0,
                'pct' => $r['avg'] ?? null, 'band' => $r['band'] ?? null, 'color' => $r['color'] ?? null];
        });
        // a district that is inactive now but has ratings in the period still shows
        foreach ($rated->except($rows->pluck('id')->all()) as $r) {
            $rows->push(['id' => $r['id'], 'name' => $r['name'], 'rated' => $r['rated'], 'schools' => $r['schools'], 'pct' => $r['avg'], 'band' => $r['band'] ?? null, 'color' => $r['color'] ?? null]);
        }

        return self::rank($rows, 'pct', 'rated');
    }

    /**
     * Sort by $key (high first), then $tie (high first), then name; standard competition ranks (equal $key = equal rank).
     * Rows whose $key is null come last with rank null.
     */
    public static function rank(Collection $rows, string $key, string $tie): Collection
    {
        [$scored, $rest] = $rows->partition(fn ($r) => $r[$key] !== null);
        $scored = $scored->sort(fn ($a, $b) => [$b[$key], $b[$tie], $a['name']] <=> [$a[$key], $a[$tie], $b['name']])->values();
        $prev = null;
        $rank = 0;
        $scored = $scored->map(function ($r, $i) use ($key, &$prev, &$rank) {
            if ($prev === null || (float) $r[$key] !== (float) $prev) {
                $rank = $i + 1;
                $prev = $r[$key];
            }

            return ['rank' => $rank] + $r;
        });

        return $scored->concat($rest->sortBy('name')->map(fn ($r) => ['rank' => null] + $r)->values())->values();
    }

    public static function pct(?float $v): string
    {
        return $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
    }

    /** Rows for Excel / PDF, in the *_HEADS order. */
    public function coverageRows(User $user, array $f): array
    {
        return $this->coverageRanking($user, $f)->map(fn ($r) => [$r['rank'] ?? '—', $r['name'], $r['schools'], $r['inspected'], self::pct($r['pct']), $r['visited'], self::pct($r['visit_pct'])])->all();
    }

    public function ratingRows(User $user, array $f): array
    {
        return $this->ratingRanking($user, $f)->map(fn ($r) => [$r['rank'] ?? '—', $r['name'], $r['rated'], $r['schools'], $r['pct'] === null ? 'Not rated' : self::pct($r['pct']), $r['band'] ?? '—'])->all();
    }

    /** Sections for the PDF / sheets for the Excel: Coverage, then Rating for the State Admin. */
    public function sections(User $user, array $f): array
    {
        $sections = [['title' => 'Coverage ranking (by Inspection coverage %)', 'sheet' => 'Coverage ranking', 'headings' => self::COVERAGE_HEADS, 'rows' => $this->coverageRows($user, $f)]];
        if (self::showsRating($user)) {
            $sections[] = ['title' => 'Rating ranking (by average rating %)', 'sheet' => 'Rating ranking', 'headings' => self::RATING_HEADS, 'rows' => $this->ratingRows($user, $f)];
        }

        return $sections;
    }
}
