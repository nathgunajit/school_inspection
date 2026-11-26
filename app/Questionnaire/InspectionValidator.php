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
        if (! empty($q['hidden'])) {
            return false; // Modification V9: question no longer asked (e.g. person met)
        }
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

    /** The remarks box is shown for this answer. */
    public static function remarksShown(array $q, mixed $value): bool
    {
        return ! empty($q['remarks_if']) && self::matches($value, $q['remarks_if']);
    }

    /** The remark must be filled (not for remarks_optional questions such as NET_01, since 25-11-2026). */
    public static function remarksRequired(array $q, mixed $value): bool
    {
        return empty($q['remarks_optional']) && self::remarksShown($q, $value);
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

    /** Step 3 switch ABS_00 ("All teachers present") is ticked. */
    public static function allTeachersPresent(array $answers): bool
    {
        return in_array('yes', (array) ($answers['ABS_00'] ?? []), true);
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

        // Step 2: teacher numbers must agree (blocking). Checked only once the numbers are entered.
        foreach ($this->teacherConsistency() as [$code, $msg]) {
            $add(2, $code, $msg);
        }

        // Step 3 follows step 2 (21-11-2026): absent = Total teachers - Present. Exactly that many absent rows; none needed when 0.
        $absentRows = $this->inspection->absentTeachers->count();
        $expected = self::absentLimit($this->answers);
        if ($expected === null) {
            $add(3, 'ABSENTEE', 'Enter Total teachers and Teachers present on step 2 first');
        } elseif ($expected === 0 && $absentRows > 0) {
            $add(3, 'ABSENTEE', "All teachers are present (step 2): remove the {$absentRows} absent teacher row(s), or correct step 2.");
        } elseif ($absentRows < $expected) {
            $add(3, 'ABSENTEE', "Add the details of all {$expected} absent teacher(s) ({$absentRows} added)");
        } elseif ($absentRows > $expected) {
            $add(3, 'ABSENTEE', "{$absentRows} absent teachers added, but only {$expected} are absent (Total teachers - Present on step 2). Remove the extra rows or correct step 2.");
        }

        // 26-11-2026: no more absent teachers of a type than step 2 has of that type
        foreach (self::absentTypeProblems($this->answers, $this->inspection->absentTeachers->pluck('teacher_type')->all()) as $msg) {
            $add(3, 'ABSENTEE', $msg);
        }

        // Step 5: fund details are optional (Modification V9)

        // Step 4: at least one classroom observed
        if ($this->inspection->classroomObservations->isEmpty()) {
            $add(4, 'CLASSROOM', 'Add at least one classroom performance record');
        }
        // 26-11-2026: a teacher marked absent today cannot be the observed classroom teacher
        $absent = $this->inspection->absentTeachers->mapWithKeys(fn ($t) => [\App\Models\InspectionAbsentTeacher::sameName($t->teacher_name) => $t->teacher_name]);
        foreach ($this->inspection->classroomObservations as $row) {
            if ($absent->has(\App\Models\InspectionAbsentTeacher::sameName($row->teacher_name))) {
                $add(4, 'CLASSROOM', self::observedAbsentMessage($row->teacher_name));
            }
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

    /**
     * Step 2 consistency (blocking):
     *  - Trained + Untrained and Male + Female (TSI_10..13; TSI_14 + TSI_15 for a V10 totals-only inspection) must be equal to Total teachers (TSI_04..08);
     *  - teachers present (TSI_09) cannot be more than Total teachers.
     *
     * @return array<int, array{0:string, 1:string}> [code, message]
     */
    public function teacherConsistency(): array
    {
        $out = [];
        $total = QuestionnaireDefinition::totalTeachers($this->answers);
        if ($total === null) {
            return $out;
        }
        $present = $this->answers['TSI_09'] ?? null;
        if (is_numeric($present) && (int) $present > $total) {
            $out[] = ['TSI_09', "Teachers present ({$present}) cannot be more than Total teachers ({$total})."];
        }
        $b = QuestionnaireDefinition::teacherBreakdown($this->answers);
        if ($b !== null && $b['sum'] !== $total) {
            // Trained + Untrained must be equal to Total teachers. Reported on TSI_10; a V10 totals-only inspection (rolled back) on TSI_14.
            $how = $b['sum'] > $total ? 'more than' : 'less than';
            $out[] = [$b['split'] ? 'TSI_10' : 'TSI_14',
                "Trained ({$b['trained']}) + Untrained ({$b['untrained']}) = {$b['sum']}, which is {$how} Total teachers ({$total}). Trained + Untrained must be equal to Total teachers."];
        }

        return $out;
    }

    /**
     * Teachers absent today = Total teachers - Present (TSI_09, required since 21-11-2026).
     * Step 3 needs exactly this many absent rows (none when 0). Null until both numbers are entered.
     */
    public static function absentLimit(array $answers): ?int
    {
        $total = QuestionnaireDefinition::totalTeachers($answers);
        if ($total === null) {
            return null;
        }
        $present = QuestionnaireDefinition::withDefaults($answers)['TSI_09'] ?? null;

        return is_numeric($present) ? max(0, $total - (int) $present) : null;
    }

    /**
     * 26-11-2026: absent teacher types against the step 2 counts (InspectionAbsentTeacher::TYPE_COUNT_CODES).
     *
     * @param  list<?string>  $types  the teacher_type of every absent row
     * @return list<string> one message per type with too many absent rows, each saying how to fix it
     */
    public static function absentTypeProblems(array $answers, array $types): array
    {
        $counts = \App\Models\InspectionAbsentTeacher::typeCounts($answers);
        if ($counts === null) {
            return [];
        }
        $out = [];
        foreach (array_count_values(array_filter($types, 'is_string')) as $type => $n) {
            $have = $counts[$type] ?? null;
            if ($have !== null && $n > $have) {
                $out[] = self::absentTypeMessage($type, $have, $n);
            }
        }

        return $out;
    }

    public static function absentTypeMessage(string $type, int $have, int $absent): string
    {
        $label = \App\Models\InspectionAbsentTeacher::TYPES[$type] ?? $type;

        return $have === 0
            ? "Step 2 has no {$label} (0). Choose another type for this teacher, or enter the number of {$label}s on step 2."
            : "Step 2 has {$have} {$label}(s), but {$absent} are marked absent. Change the type of the extra row(s), or correct the number on step 2.";
    }

    public static function observedAbsentMessage(string $name): string
    {
        return trim($name)." is marked absent today on step 3. Choose a teacher who is present, or remove them from the absent list.";
    }

    /** Non-blocking hints (shown, but do not stop submission). */
    public function warnings(): array
    {
        // the step 3 "absent but not entered" hint became a blocking rule on 21-11-2026 (missing())
        return [];
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
            if ($step === 3) {
                $req += max(1, (int) self::absentLimit($this->answers)); // one per absent teacher (step 2)
            } elseif ($step === 4) {
                $req++; // one classroom; fund years are optional (V9)
            }
            if ($step === $photoStep) {
                $req += count(config('inspection.mandatory_photos'));
            }
            $miss = count($missing[$step] ?? []);
            $req = max($req, $miss);
            $done = $req - $miss;
            if ($step === 3 && $miss && ($exp = self::absentLimit($this->answers)) > 0) {
                $done = min($this->inspection->absentTeachers->count(), $exp - 1); // e.g. 2 of 3 absent teachers entered
            }
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
