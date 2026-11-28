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
 * questionnaire version: the answers (and their remarks) of steps 1, 2, 5 and 6, the fund years and
 * (V12 item 4) the classroom observations with their TLM ticks, ready to edit.
 * Never copied: today's figures (FRESH), absent teachers, suggestions and photographs.
 * V12 item 1: Teachers present (TSI_09) starts at 0, so the officer enters today's figure.
 * Only a brand-new draft is filled; the source inspection is read, never changed.
 */
class InspectionPrefill
{
    /** Asked fresh every visit: students present / absent, teachers present, network problem today. */
    public const FRESH = ['TSI_01', 'TSI_02', 'TSI_09', 'NET_01', 'NET_02'];

    /** Steps whose answers are copied (the banner also shows on step 4, whose rows are copied). */
    public const STEPS = [1, 2, 5, 6];

    public const BANNER_STEPS = [1, 2, 4, 5, 6];

    /** V12 item 1: asked fresh, but shown as 0 on the next visit. */
    public const ZERO = ['TSI_09'];

    public function source(Inspection $new): ?Inspection
    {
        // 25-11-2026: copy only from the officer's own or a lower level's inspection (InspectionVisibility)
        return \App\Support\InspectionVisibility::apply(Inspection::query(), $new->officer)->where('school_id', $new->school_id)->whereKeyNot($new->id)
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
        $src->loadMissing(['answers', 'fundPositions', 'classroomObservations.tlmItems']);
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

        foreach (self::ZERO as $code) {
            $new->answers()->create(['step' => Q::find($code)['step'], 'question_code' => $code, 'value' => 0]);
        }

        foreach ($src->classroomObservations as $c) { // a fresh row (new row_uid space), ticks kept even if an item is now inactive
            $row = $new->classroomObservations()->create($c->only(['teacher_name', 'class', 'subject', 'tlm_used', 'participation', 'remarks']));
            $row->tlmItems()->sync($c->tlmItems->pluck('id'));
        }

        $new->forceFill(['prefilled_from_id' => $src->id])->save();
        AuditLogger::log('inspection_prefilled', $new, ['from' => $src->inspection_no, 'answers' => $copied, 'fund_years' => $src->fundPositions->count(), 'classrooms' => $src->classroomObservations->count()]);

        return $copied;
    }
}
