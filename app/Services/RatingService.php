<?php

namespace App\Services;

use App\Enums\InspectionStatus;
use App\Models\Inspection;
use App\Models\InspectionRating;
use App\Models\RatingScheme;
use App\Models\User;
use App\Questionnaire\QuestionnaireDefinition;
use App\Rating\DefaultScheme;
use App\Rating\RatingCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Automatic inspection rating (New Module 3).
 * Rates questionnaire v2 inspections on submit (and on resubmit after an authorised unlock), keeps one
 * rating per inspection (the school's history is its rated inspections), and manages the versioned schemes.
 */
class RatingService
{
    /** The active scheme; version 1 is created from DefaultScheme the first time it is needed. */
    public function activeScheme(): RatingScheme
    {
        return RatingScheme::with('criteria')->where('is_active', true)->first()
            ?? DB::transaction(function () {
                if ($s = RatingScheme::with('criteria')->where('is_active', true)->lockForUpdate()->first()) {
                    return $s;
                }

                return $this->createVersion(DefaultScheme::NAME, DefaultScheme::BANDS, DefaultScheme::criteria(), null);
            });
    }

    /** Rate a submitted inspection. Returns null for legacy (v1) or not-submitted inspections. */
    public function rate(Inspection $inspection, ?RatingScheme $scheme = null): ?InspectionRating
    {
        if ($inspection->status !== InspectionStatus::Submitted || (int) $inspection->questionnaire_version !== QuestionnaireDefinition::VERSION) {
            return null;
        }
        $scheme ??= $this->activeScheme();
        $inspection->loadMissing(['answers', 'absentTeachers', 'classroomObservations', 'fundPositions', 'school']);

        $result = RatingCalculator::calculate(
            $scheme->criteria->map(fn ($c) => $c->only(['section', 'code', 'label', 'kind', 'weight', 'points']))->all(),
            $scheme->bands,
            $inspection->answerMap(),
            [
                'absent' => $inspection->absentTeachers->map(fn ($r) => ['reason' => $r->reason])->all(),
                'classrooms' => $inspection->classroomObservations->map(fn ($r) => ['tlm_used' => $r->tlm_used, 'participation' => $r->participation])->all(),
                'funds' => $inspection->fundPositions->map(fn ($r) => $r->only(\App\Models\InspectionFundPosition::AMOUNT_COLUMNS))->all(),
            ],
        );

        $old = InspectionRating::where('inspection_id', $inspection->id)->first();
        $rating = InspectionRating::updateOrCreate(['inspection_id' => $inspection->id], [
            'scheme_id' => $scheme->id,
            'obtained' => $result['obtained'],
            'applicable' => $result['applicable'],
            'score_percent' => $result['score_percent'],
            'band' => $result['band'],
            'color' => $result['color'],
            'sections' => $result['sections'],
            'details' => $result['details'],
            'strengths' => $result['strengths'],
            'weaknesses' => $result['weaknesses'],
            'calculated_at' => now(),
        ]);

        if (! $old || $old->score_percent !== $rating->score_percent || $old->scheme_id !== $rating->scheme_id) {
            AuditLogger::log($old ? 'inspection_rerated' : 'inspection_rated', $inspection, [
                'inspection_no' => $inspection->inspection_no,
                'old_score' => $old?->score_percent, 'old_band' => $old?->band,
                'new_score' => $rating->score_percent, 'new_band' => $rating->band,
                'scheme_version' => $scheme->version,
            ]);
        }

        return $rating->setRelation('scheme', $scheme);
    }

    /** Rate every submitted v2 inspection (all, or only those not rated yet). Returns the number rated. */
    public function recalculate(bool $all = true): int
    {
        $scheme = $this->activeScheme();
        $count = 0;
        Inspection::query()
            ->where('status', InspectionStatus::Submitted)
            ->where('questionnaire_version', QuestionnaireDefinition::VERSION)
            ->when(! $all, fn ($q) => $q->whereDoesntHave('rating'))
            ->with(['answers', 'absentTeachers', 'classroomObservations', 'fundPositions', 'school'])
            ->chunkById(200, function ($chunk) use ($scheme, &$count) {
                foreach ($chunk as $inspection) {
                    $this->rate($inspection, $scheme) && $count++;
                }
            });

        return $count;
    }

    /**
     * Save changed weights, points and bands as a NEW active version. Earlier ratings keep their version.
     *
     * @param  array  $criteria  id => ['weight' => .., 'points' => [value => ..]] (keys from the current scheme)
     */
    public function saveNewVersion(User $by, array $criteria, array $bands): RatingScheme
    {
        $current = $this->activeScheme();
        $rows = $current->criteria->map(function ($c) use ($criteria) {
            $in = $criteria[$c->id] ?? [];
            $points = $c->points;
            if ($c->kind === 'answer' && isset($in['points']) && is_array($in['points'])) {
                foreach ($points as $value => $old) {
                    if (isset($in['points'][$value]) && is_numeric($in['points'][$value])) {
                        $points[$value] = round(max(0, min(1, (float) $in['points'][$value])), 4);
                    }
                }
            }

            return [
                'section' => $c->section, 'code' => $c->code, 'label' => $c->label, 'kind' => $c->kind,
                'weight' => isset($in['weight']) && is_numeric($in['weight']) ? round(max(0, (float) $in['weight']), 2) : $c->weight,
                'points' => $points,
            ];
        })->all();

        $scheme = DB::transaction(fn () => $this->createVersion(DefaultScheme::NAME, $bands, $rows, $by));
        AuditLogger::log('rating_scheme_saved', $scheme, ['version' => $scheme->version, 'previous_version' => $current->version]);

        return $scheme;
    }

    private function createVersion(string $name, array $bands, array $criteria, ?User $by): RatingScheme
    {
        RatingScheme::where('is_active', true)->update(['is_active' => false]);
        usort($bands, fn ($a, $b) => $b['min'] <=> $a['min']);
        $scheme = RatingScheme::create([
            'version' => (int) RatingScheme::max('version') + 1,
            'name' => $name,
            'is_active' => true,
            'bands' => array_values($bands),
            'created_by' => $by?->id,
            'activated_at' => now(),
        ]);
        foreach (array_values($criteria) as $i => $c) {
            $scheme->criteria()->create($c + ['sort' => $i + 1]);
        }

        return $scheme->load('criteria');
    }
}
