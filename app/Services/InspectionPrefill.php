<?php

namespace App\Services;

use App\Enums\InspectionStatus;
use App\Models\Inspection;
use App\Models\InspectionFundPosition;
use App\Questionnaire\InspectionValidator;
use App\Questionnaire\QuestionnaireDefinition as Q;

/**
 * Modification V11 item 4: the next visit to a school starts from the previous inspection.
 *
 * Copied from the school's latest submitted (or unlocked) inspection, by any officer, on the current
 * questionnaire version: the answers (and their remarks) of steps 1, 2, 5 and 6, and the fund years.
 * Never copied: today's figures (FRESH), absent teachers, classrooms, suggestions and photographs.
 * Only a brand-new draft is filled; the source inspection is read, never changed.
 */
class InspectionPrefill
{
    /** Asked fresh every visit: students present / absent, teachers present, network problem today. */
    public const FRESH = ['TSI_01', 'TSI_02', 'TSI_09', 'NET_01'];

    public const STEPS = [1, 2, 5, 6];

    public function source(Inspection $new): ?Inspection
    {
        return Inspection::where('school_id', $new->school_id)->whereKeyNot($new->id)
            ->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Unlocked])
            ->where('questionnaire_version', Q::VERSION)
            ->latest('inspection_date')->latest('id')->first();
    }

    /** @return int number of answers copied (0 when there is no earlier inspection) */
    public function apply(Inspection $new): int
    {
        $src = $this->source($new);
        if (! $src) {
            return 0;
        }
        $src->loadMissing(['answers', 'fundPositions']);
        $answers = $src->answerMap();

        $copied = 0;
        foreach ($src->answers as $a) {
            $q = Q::find($a->question_code);
            if (! $q || ! empty($q['hidden']) || in_array($a->question_code, self::FRESH, true) || ! in_array($q['step'], self::STEPS, true)
                || ! InspectionValidator::visible($q, $answers) || ! InspectionValidator::enabled($q, $answers)
                || ($a->value === null && $a->remarks === null)) {
                continue;
            }
            $new->answers()->create(['step' => $q['step'], 'question_code' => $a->question_code, 'value' => $a->value, 'remarks' => $a->remarks]);
            $copied++;
        }

        foreach ($src->fundPositions as $fp) {
            $new->fundPositions()->create(collect($fp->only(array_diff((new InspectionFundPosition)->getFillable(), ['inspection_id'])))->all());
        }

        $new->forceFill(['prefilled_from_id' => $src->id])->save();
        AuditLogger::log('inspection_prefilled', $new, ['from' => $src->inspection_no, 'answers' => $copied, 'fund_years' => $src->fundPositions->count()]);

        return $copied;
    }
}
