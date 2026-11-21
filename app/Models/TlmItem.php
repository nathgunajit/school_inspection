<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** TLM master (FLN and, since Modification V10, ECCE): items ticked on a classroom observation when TLM was used (step 4). */
class TlmItem extends Model
{
    use HasEncryptedRouteKey;

    /** The TLM lists, in the order they are shown on step 4. */
    public const LISTS = ['fln' => 'FLN TLM (2026-27)', 'ecce' => 'ECCE TLM (2025-26)'];

    protected $fillable = ['list', 'class', 'section', 'name', 'code', 'variety', 'remarks', 'sort', 'is_active'];

    protected $attributes = ['list' => 'fln'];

    protected $casts = ['is_active' => 'boolean', 'variety' => 'integer', 'sort' => 'integer'];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Active items of one list (FLN by default) for a class: that class's items plus the "All classes" ones. */
    public function scopeForClass(Builder $q, ?string $class, string $list = 'fln'): Builder
    {
        return $q->active()->where('list', $list)->where(fn ($w) => $w->where('class', $class)->orWhereNull('class'))->orderBy('sort')->orderBy('name');
    }

    public function classLabel(): string
    {
        return $this->class === null ? 'All classes' : (InspectionClassroomObservation::CLASSES[$this->class] ?? $this->class);
    }

    public function listLabel(): string
    {
        return self::LISTS[$this->list] ?? strtoupper((string) $this->list);
    }

    /** Short list name: "FLN" / "ECCE". */
    public function listShort(): string
    {
        return strtoupper((string) $this->list);
    }

    /** Name with its code, e.g. "Karjyapatra (Literacy) · NIPUN AXOM G1L4"; ECCE items read "ECCE · Number Card". */
    public function fullLabel(): string
    {
        return ($this->list === 'ecce' ? 'ECCE · ' : '').$this->name.($this->code ? ' · '.$this->code : '');
    }

    public function toPickArray(): array
    {
        return ['id' => $this->id, 'list' => $this->list, 'class' => $this->class, 'section' => $this->section, 'name' => $this->name, 'code' => $this->code];
    }
}
