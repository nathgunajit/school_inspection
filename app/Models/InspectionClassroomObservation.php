<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Model;

/** Class Room Performance rows (questionnaire v2, step 4). */
class InspectionClassroomObservation extends Model
{
    use HasEncryptedRouteKey;

    public const CLASSES = ['I' => 'Class I', 'II' => 'Class II', 'III' => 'Class III', 'IV' => 'Class IV', 'V' => 'Class V', 'VI' => 'Class VI',
        'VII' => 'Class VII', 'VIII' => 'Class VIII', 'IX' => 'Class IX', 'X' => 'Class X', 'XI' => 'Class XI', 'XII' => 'Class XII'];

    public const PARTICIPATION = ['satisfactory' => 'Satisfactory', 'not_satisfactory' => 'Not Satisfactory'];

    protected $fillable = ['inspection_id', 'row_uid', 'teacher_name', 'class', 'subject', 'tlm_used', 'participation', 'remarks'];

    protected $casts = ['tlm_used' => 'boolean'];

    public function participationLabel(): string
    {
        return self::PARTICIPATION[$this->participation] ?? $this->participation;
    }

    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'teacher_name' => $this->teacher_name,
            'class' => self::CLASSES[$this->class] ?? $this->class,
            'subject' => $this->subject,
            'tlm' => $this->tlm_used ? 'Yes' : 'No',
            'participation' => $this->participationLabel(),
            'remarks' => $this->remarks,
            'tlm_ok' => (bool) $this->tlm_used,
            'participation_ok' => $this->participation === 'satisfactory',
            'values' => ['teacher_name' => $this->teacher_name, 'class' => $this->class, 'subject' => $this->subject,
                'tlm_used' => $this->tlm_used ? 'yes' : 'no', 'participation' => $this->participation, 'remarks' => $this->remarks],
            'update_url' => route('inspections.classrooms.update', [Inspection::routeKeyFor($this->inspection_id), $this]),
            'delete_url' => route('inspections.classrooms.destroy', [Inspection::routeKeyFor($this->inspection_id), $this]),
        ];
    }
}
