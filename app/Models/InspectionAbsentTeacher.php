<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Model;

/** Teachers Absentee Form rows (questionnaire v2, step 3). */
class InspectionAbsentTeacher extends Model
{
    use HasEncryptedRouteKey;

    public const TYPES = [
        'regular' => 'Regular Teacher',
        'contractual' => 'Contractual Teacher',
        'tutor' => 'Tutor',
    ];

    public const REASONS = [
        'CL' => 'Casual Leave (CL)',
        'EL' => 'Earned Leave (EL)',
        'MATL' => 'Maternity Leave (ML)',
        'CCL' => 'Child Care Leave (CCL)',
        'SL' => 'Study Leave (SL)',
        'MEDL' => 'Medical Leave (ML)',
        'UL' => 'Un-authorised Leave (UL)',
    ];

    /** v1 statuses, kept for legacy rows. */
    public const STATUSES = [
        'on_leave' => 'On leave',
        'official_duty' => 'On official duty',
        'absent_without_info' => 'Absent without information',
    ];

    /**
     * 26-11-2026: the step 2 counts each absent teacher type is taken from (Regular includes SSA teachers).
     * An inspection cannot have more absent teachers of a type than step 2 has of that type.
     */
    public const TYPE_COUNT_CODES = [
        'regular' => ['TSI_04', 'TSI_06'],
        'contractual' => ['TSI_05'],
        'tutor' => ['TSI_07', 'TSI_08'],
    ];

    protected $fillable = ['inspection_id', 'row_uid', 'teacher_name', 'teacher_type', 'reason', 'status', 'remarks'];

    /** How many teachers of each type step 2 has (null until Regular teachers is entered). @return array<string,int>|null */
    public static function typeCounts(array $answers): ?array
    {
        $a = \App\Questionnaire\QuestionnaireDefinition::withDefaults($answers);
        if (! is_numeric($a['TSI_04'] ?? null)) {
            return null;
        }

        return collect(self::TYPE_COUNT_CODES)->map(fn ($codes) => array_sum(array_map(fn ($c) => is_numeric($a[$c] ?? null) ? (int) $a[$c] : 0, $codes)))->all();
    }

    /** "Rahul  das " and "rahul das" are the same teacher. */
    public static function sameName(?string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $name)));
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->teacher_type] ?? '—';
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? (self::STATUSES[$this->status] ?? '—');
    }

    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'teacher_name' => $this->teacher_name,
            'type' => $this->typeLabel(),
            'reason' => $this->reasonLabel(),
            'remarks' => $this->remarks,
            'values' => ['teacher_name' => $this->teacher_name, 'teacher_type' => $this->teacher_type, 'reason' => $this->reason, 'remarks' => $this->remarks],
            'update_url' => route('inspections.teachers.update', [Inspection::routeKeyFor($this->inspection_id), $this]),
            'delete_url' => route('inspections.teachers.destroy', [Inspection::routeKeyFor($this->inspection_id), $this]),
        ];
    }
}
