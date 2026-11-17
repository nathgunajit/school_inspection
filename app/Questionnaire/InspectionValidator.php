<?php

namespace App\Questionnaire;

use App\Enums\EvidenceStatus;
use App\Models\Inspection;

/**
 * Server-side evaluation of the questionnaire rules: visibility, mandatory answers,
 * remarks, linked photos, attendance arithmetic and mandatory photographs (PRD 8.3, 9.3, 10.2).
 */
class InspectionValidator
{
    private array $answers;
    private array $remarks;
    private array $uploadedByQuestion = [];
    private array $uploadedByCategory = [];

    public function __construct(private Inspection $inspection)
    {
        $inspection->loadMissing(['answers', 'absentTeachers', 'fundPositions', 'evidence.category', 'remarks']);
        $this->answers = $inspection->answerMap();
        $this->remarks = $inspection->remarksMap();

        foreach ($inspection->evidence as $e) {
            if ($e->kind !== 'photo' || $e->status !== EvidenceStatus::Uploaded) {
                continue;
            }
            if ($e->question_code) {
                $this->uploadedByQuestion[$e->question_code] = ($this->uploadedByQuestion[$e->question_code] ?? 0) + 1;
            }
            $cat = $e->category?->code;
            if ($cat) {
                $this->uploadedByCategory[$cat] = ($this->uploadedByCategory[$cat] ?? 0) + 1;
            }
        }
    }

    public static function isFilled(mixed $v): bool
    {
        return ! ($v === null || $v === '' || $v === []);
    }

    public static function visible(array $q, array $answers): bool
    {
        if (empty($q['show_if'])) {
            return true;
        }
        [$code, $values] = $q['show_if'];

        return self::matches($answers[$code] ?? null, $values);
    }

    public static function required(array $q, array $answers): bool
    {
        if (! self::visible($q, $answers)) {
            return false;
        }
        if (! empty($q['required'])) {
            return true;
        }
        if (! empty($q['required_if'])) {
            [$c, $vals] = $q['required_if'];

            return self::matches($answers[$c] ?? null, $vals);
        }
        if (! empty($q['required_unless'])) {
            [$c, $vals] = $q['required_unless'];
            $v = $answers[$c] ?? null;

            return self::isFilled($v) && ! self::matches($v, $vals);
        }

        return false;
    }

    public static function remarksRequired(array $q, mixed $value): bool
    {
        return ! empty($q['remarks_if']) && self::matches($value, $q['remarks_if']);
    }

    public static function photoRequired(array $q, mixed $value): bool
    {
        $rule = $q['photo'] ?? null;
        if ($rule === 'required') {
            return true;
        }
        if (is_array($rule) && isset($rule['required_if'])) {
            return self::matches($value, $rule['required_if']);
        }

        return false;
    }

    private static function matches(mixed $value, array $values): bool
    {
        foreach ((array) $value as $v) {
            if (in_array((string) $v, $values, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * All missing / invalid items, grouped by step.
     *
     * @return array<int, array<int, array{code:?string, message:string}>>
     */
    public function missing(): array
    {
        $out = [];
        $add = function (int $step, ?string $code, string $msg) use (&$out) {
            $out[$step][] = ['code' => $code, 'message' => $msg];
        };

        foreach (QuestionnaireDefinition::questions() as $step => $qs) {
            foreach ($qs as $q) {
                if (! self::visible($q, $this->answers)) {
                    continue;
                }
                $value = $this->answers[$q['code']] ?? null;
                if (self::required($q, $this->answers) && ! self::isFilled($value)) {
                    $add($step, $q['code'], $q['label'].': answer required');

                    continue;
                }
                if (self::isFilled($value) && self::remarksRequired($q, $value) && ! self::isFilled($this->remarks[$q['code']] ?? null)) {
                    $add($step, $q['code'], $q['label'].': remarks required');
                }
                if (self::isFilled($value) && self::photoRequired($q, $value) && empty($this->uploadedByQuestion[$q['code']])) {
                    $add($step, $q['code'], $q['label'].': photograph required');
                }
            }
        }

        // Step 4 arithmetic (PRD 8.3 step 4)
        $a = $this->answers;
        $num = fn ($c) => isset($a[$c]) && is_numeric($a[$c]) ? (int) $a[$c] : null;
        [$sanc, $work, $pres, $abs] = [$num('ATT_SANCTIONED'), $num('ATT_WORKING'), $num('ATT_PRESENT'), $num('ATT_ABSENT')];
        if ($work !== null && $pres !== null && $abs !== null && $pres + $abs !== $work) {
            $add(4, 'ATT_ABSENT', 'Teachers present plus absent must equal working teachers');
        }
        if ($sanc !== null && $work !== null && $work > $sanc) {
            $add(4, 'ATT_WORKING', 'Working teachers cannot exceed sanctioned posts');
        }
        if ($abs !== null && $abs !== $this->inspection->absentTeachers->count()) {
            $add(4, 'ATT_ABSENT', "Details required for each absent teacher ({$this->inspection->absentTeachers->count()} of {$abs} entered)");
        }
        $enr = $num('ATT_ENROLLED');
        $sp = $num('ATT_STUDENTS_PRESENT');
        if ($enr !== null && $sp !== null && $sp > $enr) {
            $add(4, 'ATT_STUDENTS_PRESENT', 'Students present cannot exceed enrolment');
        }

        // Fund position: a cash-book photograph is required if a fund row was entered
        if ($this->inspection->fundPositions->isNotEmpty() && empty($this->uploadedByCategory['cash_book'])) {
            $add(5, 'FUND', 'Photograph of the cash book page required');
        }

        // Mandatory photographs (PRD 9.3)
        $labels = ['school_front' => 'School front with name board', 'classroom' => 'Classroom in session', 'toilet' => 'Toilets', 'kitchen_mdm' => 'Kitchen or Mid-Day Meal'];
        foreach (config('inspection.mandatory_photos') as $cat => $min) {
            if (($this->uploadedByCategory[$cat] ?? 0) < $min) {
                $add(6, 'PHOTO_'.$cat, 'Photograph required: '.($labels[$cat] ?? $cat));
            }
        }

        // Evidence still uploading
        $pending = $this->inspection->evidence->where('kind', 'photo')->filter(fn ($e) => $e->status !== EvidenceStatus::Uploaded)->count();
        if ($pending) {
            $add(6, null, "{$pending} photograph(s) not yet uploaded");
        }

        ksort($out);

        return $out;
    }

    /** Completion percentage per step and overall, based on required items. */
    public function progress(): array
    {
        $missing = $this->missing();
        $steps = [];
        $totalReq = 0;
        $totalDone = 0;

        foreach (QuestionnaireDefinition::questions() as $step => $qs) {
            $req = 0;
            foreach ($qs as $q) {
                if (self::required($q, $this->answers)) {
                    $req++;
                }
            }
            if ($step === 6) {
                $req += count(config('inspection.mandatory_photos'));
            }
            $miss = count($missing[$step] ?? []);
            $req = max($req, $miss);
            $done = $req - $miss;
            $steps[$step] = [
                'required' => $req,
                'done' => $done,
                'complete' => $miss === 0,
                'pct' => $req ? (int) floor($done / $req * 100) : 100,
            ];
            $totalReq += $req;
            $totalDone += $done;
        }

        return [
            'steps' => $steps,
            'pct' => $totalReq ? (int) floor($totalDone / $totalReq * 100) : 100,
            'missing' => $missing,
            'can_submit' => empty($missing),
        ];
    }
}
