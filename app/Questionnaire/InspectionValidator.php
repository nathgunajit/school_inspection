<?php

namespace App\Questionnaire;

use App\Enums\EvidenceStatus;
use App\Models\Inspection;

/**
 * Server-side evaluation of the questionnaire rules (v2): visibility, mandatory answers,
 * remarks, repeating sections, mandatory photographs and pending uploads.
 */
class InspectionValidator
{
    private array $answers;
    private array $remarks;
    private array $uploadedByQuestion = [];
    private array $uploadedByCategory = [];

    public function __construct(private Inspection $inspection)
    {
        $inspection->loadMissing(['answers', 'absentTeachers', 'classroomObservations', 'fundPositions', 'evidence.category', 'remarks']);
        $this->answers = $inspection->answerMap();
        $this->remarks = $inspection->remarksMap();

        foreach ($inspection->evidence as $e) {
            if ($e->kind !== 'photo' || $e->status !== EvidenceStatus::Uploaded) {
                continue;
            }
            if ($e->question_code) {
                $this->uploadedByQuestion[$e->question_code] = ($this->uploadedByQuestion[$e->question_code] ?? 0) + 1;
            }
            if ($cat = $e->category?->code) {
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

    /** enable_if: the question is always shown, but greyed out (no answer, not required) unless the parent matches. */
    public static function enabled(array $q, array $answers): bool
    {
        if (empty($q['enable_if'])) {
            return true;
        }
        [$code, $values] = $q['enable_if'];

        return self::matches($answers[$code] ?? null, $values);
    }

    public static function required(array $q, array $answers): bool
    {
        if (! self::visible($q, $answers) || ! self::enabled($q, $answers)) {
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

    /** Last step holds the photographs. */
    public static function photoStep(): int
    {
        return max(array_keys(QuestionnaireDefinition::steps()));
    }

    /**
     * Blocking missing items, grouped by step.
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
                if (! self::visible($q, $this->answers) || ! self::enabled($q, $this->answers)) {
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

        // Step 4: at least one classroom observed
        if ($this->inspection->classroomObservations->isEmpty()) {
            $add(4, 'CLASSROOM', 'Add at least one classroom performance record');
        }

        // Mandatory photographs
        $photoStep = self::photoStep();
        $labels = \App\Models\MasterValue::where('type', 'photo_category')->pluck('label', 'code');
        foreach (config('inspection.mandatory_photos') as $cat => $min) {
            if (($this->uploadedByCategory[$cat] ?? 0) < $min) {
                $add($photoStep, 'PHOTO_'.$cat, 'Photograph required: '.($labels[$cat] ?? $cat));
            }
        }

        $pending = $this->inspection->evidence->where('kind', 'photo')->filter(fn ($e) => $e->status !== EvidenceStatus::Uploaded)->count();
        if ($pending) {
            $add($photoStep, null, "{$pending} photograph(s) not yet uploaded");
        }

        ksort($out);

        return $out;
    }

    /** Non-blocking hints (shown, but do not stop submission). */
    public function warnings(): array
    {
        $w = [];
        $total = QuestionnaireDefinition::totalTeachers($this->answers);
        $present = $this->answers['TSI_09'] ?? null;
        if ($total !== null && is_numeric($present) && (int) $present > $total) {
            $w[2][] = "Teachers present ({$present}) is more than total teachers and tutors ({$total}).";
        }
        if ($total !== null && is_numeric($present)) {
            $absent = $total - (int) $present;
            $rows = $this->inspection->absentTeachers->count();
            if ($absent > 0 && $rows < $absent) {
                $w[3][] = "{$absent} teacher(s) appear absent; {$rows} entered in the absentee form.";
            }
        }

        return $w;
    }

    /** Completion percentage per step and overall, based on required items. */
    public function progress(): array
    {
        $missing = $this->missing();
        $steps = [];
        $totalReq = 0;
        $totalDone = 0;
        $photoStep = self::photoStep();

        foreach (QuestionnaireDefinition::steps() as $step => $_) {
            $req = 0;
            foreach (QuestionnaireDefinition::questions()[$step] ?? [] as $q) {
                if (self::required($q, $this->answers)) {
                    $req++;
                }
            }
            if ($step === 4) {
                $req++;
            }
            if ($step === $photoStep) {
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
            'warnings' => $this->warnings(),
            'can_submit' => empty($missing),
        ];
    }
}
