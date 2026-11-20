<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Class Room Performance rows (questionnaire v2, step 4). */
class InspectionClassroomObservation extends Model
{
    use HasEncryptedRouteKey;

    public const CLASSES = ['I' => 'Class I', 'II' => 'Class II', 'III' => 'Class III', 'IV' => 'Class IV', 'V' => 'Class V', 'VI' => 'Class VI',
        'VII' => 'Class VII', 'VIII' => 'Class VIII', 'IX' => 'Class IX', 'X' => 'Class X', 'XI' => 'Class XI', 'XII' => 'Class XII'];

    public const PARTICIPATION = ['satisfactory' => 'Satisfactory', 'not_satisfactory' => 'Not Satisfactory'];

    protected $fillable = ['inspection_id', 'row_uid', 'teacher_name', 'class', 'subject', 'tlm_used', 'participation', 'remarks'];

    protected $casts = ['tlm_used' => 'boolean'];

    /** FLN TLM items used (ticked when tlm_used = yes), from the TLM master. */
    public function tlmItems(): BelongsToMany
    {
        return $this->belongsToMany(TlmItem::class, 'inspection_classroom_tlm', 'classroom_observation_id', 'tlm_item_id')->orderBy('sort')->orderBy('name');
    }

    /** "Yes: Karjyapatra (Literacy), HPC" / "Yes" / "No" for reports. */
    public function tlmText(): string
    {
        if (! $this->tlm_used) {
            return 'No';
        }
        $names = $this->tlmItems->map->fullLabel()->all();

        return $names ? 'Yes: '.implode(', ', $names) : 'Yes';
    }

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
            'tlm' => $this->tlm_used ? 'Yes'.($this->tlmItems->count() ? ' · '.$this->tlmItems->count().' item'.($this->tlmItems->count() > 1 ? 's' : '') : '') : 'No',
            'tlm_items' => $this->tlmItems->map->fullLabel()->values()->all(),
            'participation' => $this->participationLabel(),
            'remarks' => $this->remarks,
            'tlm_ok' => (bool) $this->tlm_used,
            'participation_ok' => $this->participation === 'satisfactory',
            'values' => ['teacher_name' => $this->teacher_name, 'class' => $this->class, 'subject' => $this->subject,
                'tlm_used' => $this->tlm_used ? 'yes' : 'no', 'tlm_items' => $this->tlmItems->pluck('id')->all(), 'participation' => $this->participation, 'remarks' => $this->remarks],
            'update_url' => route('inspections.classrooms.update', [Inspection::routeKeyFor($this->inspection_id), $this]),
            'delete_url' => route('inspections.classrooms.destroy', [Inspection::routeKeyFor($this->inspection_id), $this]),
        ];
    }
}
