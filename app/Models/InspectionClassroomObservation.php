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

    /**
     * TLM ticks split by list (FLN, then ECCE; only lists with ticks), for the coloured groups on step 4, the report and the PDF.
     * Items read "name · code" without the "ECCE · " prefix: the group says which list.
     *
     * @return array<int, array{list:string, short:string, label:string, items:array<int,string>}>
     */
    public function tlmGroups(): array
    {
        if (! $this->tlm_used) {
            return [];
        }
        $out = [];
        foreach (array_keys(TlmItem::LISTS) as $key) {
            $mine = $this->tlmItems->filter(fn (TlmItem $t) => ($t->list ?: 'fln') === $key);
            if ($mine->isEmpty()) {
                continue;
            }
            $first = $mine->first();
            $out[] = ['list' => $key, 'short' => $first->listShort(), 'label' => $first->listLabel(),
                'items' => $mine->map(fn (TlmItem $t) => $t->name.($t->code ? ' · '.$t->code : ''))->values()->all()];
        }

        return $out;
    }

    /** "Yes · FLN 2 · ECCE 1" / "Yes" / "No". */
    public function tlmSummary(): string
    {
        if (! $this->tlm_used) {
            return 'No';
        }

        return collect($this->tlmGroups())->map(fn ($g) => $g['short'].' '.count($g['items']))->prepend('Yes')->implode(' · ');
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
            'tlm' => $this->tlmSummary(),
            'tlm_items' => $this->tlmItems->map->fullLabel()->values()->all(),
            'tlm_groups' => $this->tlmGroups(),
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
