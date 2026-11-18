<?php

namespace App\Rating;

/**
 * Version 1 of the rating scheme (New Module 3). The State Admin can change weights, points and bands
 * on the Rating Settings screen; each save becomes a new scheme version.
 *
 * Answer criteria: points per answer value (0..1). An answer value that is not listed (for example
 * "na") makes the criterion Not Applicable, and so does a question hidden or disabled by its parent.
 * Metric criteria: a ratio (0..1) worked out from the inspection, see RatingCalculator::metric().
 */
class DefaultScheme
{
    public const NAME = 'Default rating scheme';

    /** Band = the first row whose min the score reaches. Colour keys: green, blue, yellow, orange, red. */
    public const BANDS = [
        ['min' => 90, 'label' => 'Excellent', 'color' => 'green'],
        ['min' => 75, 'label' => 'Very Good', 'color' => 'blue'],
        ['min' => 60, 'label' => 'Good', 'color' => 'yellow'],
        ['min' => 40, 'label' => 'Needs Improvement', 'color' => 'orange'],
        ['min' => 0, 'label' => 'Unsatisfactory', 'color' => 'red'],
    ];

    /** Metric keys the calculator knows, with their default labels and sections. */
    public const METRICS = [
        'student_attendance' => ['strength', 'Student attendance on the day (present ÷ enrolled)'],
        'teacher_attendance' => ['strength', 'Teacher attendance on the day (present ÷ total teachers)'],
        'trained_teachers' => ['strength', 'Share of trained teachers'],
        'absentee_authorised' => ['absentee', 'Absent teachers on authorised leave (not un-authorised)'],
        'classroom_tlm' => ['classroom', 'Classrooms using TLM'],
        'classroom_participation' => ['classroom', 'Classrooms with satisfactory student participation'],
        'fund_utilisation' => ['fund', 'Fund utilisation (expenditure ÷ received)'],
    ];

    /** @return array<int, array{section: string, code: string, label: string, kind: string, weight: float, points: ?array}> */
    public static function criteria(): array
    {
        $yes = ['yes' => 1, 'no' => 0];
        $sat = ['satisfactory' => 1, 'unsatisfactory' => 0];
        $q = fn ($section, $code, $points, $weight = 1) => ['section' => $section, 'code' => $code, 'kind' => 'answer', 'weight' => $weight, 'points' => $points];
        $m = fn ($code, $weight = 3) => ['section' => self::METRICS[$code][0], 'code' => $code, 'label' => self::METRICS[$code][1], 'kind' => 'metric', 'weight' => $weight, 'points' => null];

        $rows = [$q('condition', 'CND_01', ['pucca' => 1, 'semi_pucca' => 0.5, 'others' => 0])];
        foreach (range(2, 24) as $n) {
            $code = sprintf('CND_%02d', $n);
            // CND_12 asks whether an additional classroom is REQUIRED: "no" is the good answer
            $rows[] = $q('condition', $code, $code === 'CND_12' ? ['yes' => 0, 'no' => 1] : $yes);
        }
        $rows[] = $q('condition', 'CND_26', $yes); // "na" is not listed → Not Applicable

        $rows[] = $m('student_attendance');
        $rows[] = $m('teacher_attendance');
        $rows[] = $m('trained_teachers');
        $rows[] = $m('absentee_authorised');
        $rows[] = $m('classroom_tlm');
        $rows[] = $m('classroom_participation');
        $rows[] = $m('fund_utilisation');

        $rows[] = $q('governance', 'GOVR_01', ['non_conventional' => 1, 'conventional' => 0.5]);
        foreach (['GOVR_02', 'GOVR_04', 'GOVR_05', 'GOVR_06', 'GOVR_08', 'GOVR_11', 'GOVR_12', 'GOVR_14', 'GOVR_15', 'GOVR_16', 'GOVR_17', 'GOVR_18', 'GOVR_19'] as $code) {
            $rows[] = $q('governance', $code, $yes);
        }
        foreach (['GOVR_10', 'GOVR_20', 'GOVR_21', 'GOVR_22', 'GOVR_23', 'GOVR_24'] as $code) {
            $rows[] = $q('governance', $code, $sat);
        }

        return array_map(function ($r) {
            $r['label'] ??= \App\Questionnaire\QuestionnaireDefinition::find($r['code'])['label'] ?? $r['code'];

            return $r;
        }, $rows);
    }
}
