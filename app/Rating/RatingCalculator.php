<?php

namespace App\Rating;

use App\Models\InspectionAbsentTeacher;
use App\Models\InspectionFundPosition;
use App\Questionnaire\QuestionnaireDefinition as Q;

/**
 * Works out the rating of one inspection (New Module 3). Pure: no database access.
 *
 *   Score % = Σ(weight × points) ÷ Σ(weight of the applicable criteria) × 100
 *
 * The same formula gives each section's score. A criterion is Not Applicable, and left out of the
 * denominator, when the question is hidden or disabled by its parent question, the answer is missing
 * or not in the points list (e.g. "na"), or a metric has nothing to divide by.
 */
class RatingCalculator
{
    public const SECTION_TITLES = [
        'condition' => 'Condition of the School',
        'strength' => "Teachers' & Students' Information",
        'absentee' => 'Teachers Absentee',
        'classroom' => 'Class Room Performance',
        'fund' => 'Fund Received & Expenditure',
        'governance' => 'School Governance',
    ];

    public const WEAK_SECTION_BELOW = 60;

    /**
     * @param  array  $criteria  rows with section, code, label, kind, weight, points
     * @param  array  $bands  rows with min, label, color (any order)
     * @param  array  $answers  question code => value
     * @param  array  $rows  ['absent' => [['reason' => ..]], 'classrooms' => [['tlm_used' => bool, 'participation' => ..]], 'funds' => [[column => amount]]]
     */
    public static function calculate(array $criteria, array $bands, array $answers, array $rows = []): array
    {
        $details = [];
        foreach ($criteria as $c) {
            $weight = max(0, (float) $c['weight']);
            if ($c['kind'] === 'metric') {
                [$value, $points] = self::metric($c['code'], $answers, $rows);
            } else {
                $value = $answers[$c['code']] ?? null;
                $points = self::applicable($c['code'], $answers) && $value !== null && $value !== '' && ! is_array($value)
                    && array_key_exists((string) $value, (array) $c['points'])
                    ? (float) $c['points'][(string) $value] : null;
            }
            $details[] = [
                'section' => $c['section'], 'code' => $c['code'], 'label' => $c['label'], 'kind' => $c['kind'],
                'weight' => $weight, 'value' => $value, 'points' => $points === null ? null : round(max(0, min(1, $points)), 4),
            ];
        }

        $sections = [];
        foreach (self::SECTION_TITLES as $key => $title) {
            $in = array_filter($details, fn ($d) => $d['section'] === $key);
            if (! $in) {
                continue;
            }
            [$obt, $app] = self::sum($in);
            $pct = $app > 0 ? round($obt / $app * 100, 2) : null;
            $sections[$key] = ['title' => $title, 'obtained' => round($obt, 2), 'applicable' => round($app, 2), 'percent' => $pct] + self::band($pct, $bands);
        }

        [$obtained, $applicable] = self::sum($details);
        $percent = $applicable > 0 ? round($obtained / $applicable * 100, 2) : null;

        // strengths: criteria at full points (heaviest first, at most 3 per section); weaknesses: criteria at zero
        $scored = array_values(array_filter($details, fn ($d) => $d['points'] !== null && $d['weight'] > 0));
        usort($scored, fn ($a, $b) => $b['weight'] <=> $a['weight']);
        $strengths = [];
        $perSection = [];
        foreach ($scored as $d) {
            if ($d['points'] >= 1 && ($perSection[$d['section']] = ($perSection[$d['section']] ?? 0) + 1) <= 3) {
                $strengths[] = ['section' => $d['section'], 'label' => $d['label']];
            }
        }
        $weaknesses = array_values(array_map(fn ($d) => ['section' => $d['section'], 'label' => $d['label']],
            array_filter($scored, fn ($d) => $d['points'] <= 0)));

        return [
            'obtained' => round($obtained, 2),
            'applicable' => round($applicable, 2),
            'score_percent' => $percent,
            'sections' => $sections,
            'details' => $details,
            'strengths' => $strengths,
            'weaknesses' => $weaknesses,
        ] + self::band($percent, $bands);
    }

    /** ['band' => label, 'color' => colour key] for a score; null score = not rated. */
    public static function band(?float $percent, array $bands): array
    {
        if ($percent === null) {
            return ['band' => null, 'color' => null];
        }
        usort($bands, fn ($a, $b) => $b['min'] <=> $a['min']);
        foreach ($bands as $b) {
            if ($percent >= (float) $b['min']) {
                return ['band' => $b['label'], 'color' => $b['color']];
            }
        }

        return ['band' => end($bands)['label'] ?? null, 'color' => end($bands)['color'] ?? null];
    }

    /** Sections scoring below WEAK_SECTION_BELOW %: the "areas requiring improvement". */
    public static function improvementAreas(array $sections): array
    {
        return array_values(array_filter($sections, fn ($s) => $s['percent'] !== null && $s['percent'] < self::WEAK_SECTION_BELOW));
    }

    /** One-paragraph school performance summary for the report and PDF. */
    public static function summary(array $r, string $school): string
    {
        if (($r['score_percent'] ?? null) === null) {
            return "No rated items applied to this inspection of $school.";
        }
        $sections = array_filter($r['sections'], fn ($s) => $s['percent'] !== null);
        uasort($sections, fn ($a, $b) => $b['percent'] <=> $a['percent']);
        $best = reset($sections);
        $worst = end($sections);
        $text = sprintf('%s scored %s%% overall and is rated "%s".', $school, rtrim(rtrim(number_format($r['score_percent'], 2), '0'), '.'), $r['band']);
        if ($best && $worst && $best !== $worst) {
            $text .= sprintf(' Its strongest area is %s (%s%%) and its weakest is %s (%s%%).',
                $best['title'], rtrim(rtrim(number_format($best['percent'], 1), '0'), '.'), $worst['title'], rtrim(rtrim(number_format($worst['percent'], 1), '0'), '.'));
        }
        $areas = self::improvementAreas($r['sections']);
        if ($areas) {
            $text .= ' Areas requiring improvement: '.implode(', ', array_column($areas, 'title')).'.';
        }

        return $text;
    }

    /** [Σ weight × points, Σ weight] over the applicable criteria (points !== null). */
    private static function sum(array $details): array
    {
        $obt = 0.0;
        $app = 0.0;
        foreach ($details as $d) {
            if ($d['points'] !== null) {
                $obt += $d['weight'] * $d['points'];
                $app += $d['weight'];
            }
        }

        return [$obt, $app];
    }

    /** A question hidden (show_if) or disabled (enable_if) by its parent's answer does not apply. */
    private static function applicable(string $code, array $answers): bool
    {
        $q = Q::find($code);
        foreach (['show_if', 'enable_if'] as $rule) {
            if (! empty($q[$rule])) {
                [$parent, $values] = $q[$rule];
                if (! in_array($answers[$parent] ?? null, $values, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array{0: mixed, 1: ?float} [shown value, points 0..1 or null = N/A] */
    private static function metric(string $key, array $a, array $rows): array
    {
        $n = fn ($code) => isset($a[$code]) && $a[$code] !== '' ? (float) $a[$code] : null;
        $ratio = fn ($num, $den) => $num === null || $den === null || $den <= 0 ? [null, null] : [round($num / $den * 100, 1).'%', min(1, $num / $den)];

        switch ($key) {
            case 'student_attendance':
                $p = $n('TSI_01');
                $ab = $n('TSI_02');

                return $p === null || $ab === null ? [null, null] : $ratio($p, $p + $ab);
            case 'teacher_attendance':
                $total = Q::totalTeachers($a);

                return $ratio($n('TSI_09'), $total === null ? null : (float) $total);
            case 'trained_teachers':
                // Modification V10: trained / (trained + untrained) from TSI_14 / TSI_15; older inspections use TSI_10..13
                if (! Q::legacyTeacherBreakdown($a)) {
                    $t = $n('TSI_14');
                    $u = $n('TSI_15');

                    return $t === null || $u === null ? [null, null] : $ratio($t, $t + $u);
                }
                $parts = array_map($n, ['TSI_10', 'TSI_11', 'TSI_12', 'TSI_13']);
                if (in_array(null, $parts, true)) {
                    return [null, null];
                }

                return $ratio($parts[0] + $parts[2], array_sum($parts));
            case 'absentee_authorised':
                $absent = $rows['absent'] ?? [];
                $ul = count(array_filter($absent, fn ($r) => ($r['reason'] ?? null) === 'UL'));

                return $ratio((float) (count($absent) - $ul), (float) count($absent));
            case 'classroom_tlm':
                $c = $rows['classrooms'] ?? [];

                return $ratio((float) count(array_filter($c, fn ($r) => (bool) ($r['tlm_used'] ?? false))), (float) count($c));
            case 'classroom_participation':
                $c = $rows['classrooms'] ?? [];

                return $ratio((float) count(array_filter($c, fn ($r) => ($r['participation'] ?? null) === 'satisfactory')), (float) count($c));
            case 'fund_utilisation':
                $rec = 0.0;
                $exp = 0.0;
                foreach ($rows['funds'] ?? [] as $f) {
                    foreach (InspectionFundPosition::HEADS as [, $recCol, $expCol]) {
                        $rec += (float) ($f[$recCol] ?? 0);
                        $exp += (float) ($f[$expCol] ?? 0);
                    }
                }

                return $ratio($exp, $rec);
        }

        return [null, null];
    }
}
