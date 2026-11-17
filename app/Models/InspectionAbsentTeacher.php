<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Teachers Absentee Form rows (questionnaire v2, step 3). */
class InspectionAbsentTeacher extends Model
{
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

    protected $fillable = ['inspection_id', 'teacher_name', 'teacher_type', 'reason', 'status', 'remarks'];

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
            'delete_url' => route('inspections.teachers.destroy', [$this->inspection_id, $this->id]),
        ];
    }
}
