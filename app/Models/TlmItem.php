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

    protected $fillable = ['list', 'class', 'section', 'category_ids', 'name', 'code', 'variety', 'remarks', 'sort', 'is_active'];

    protected $attributes = ['list' => 'fln'];

    protected $casts = ['is_active' => 'boolean', 'variety' => 'integer', 'sort' => 'integer', 'category_ids' => 'array'];

    /**
     * 28-11-2026: is this item used for schools of this category? No category ticked = every category;
     * a school without a category gets every item.
     */
    public function appliesTo(?int $categoryId): bool
    {
        $ids = array_map('intval', (array) ($this->category_ids ?? []));

        return $categoryId === null || $ids === [] || in_array($categoryId, $ids, true);
    }

    /** Active items offered on step 4 for a school category, in the step 4 order (FLN, then ECCE). */
    public static function offeredFor(?int $categoryId): \Illuminate\Support\Collection
    {
        return static::active()->orderByRaw("list = 'ecce'")->orderByRaw('class is null')->orderBy('sort')->orderBy('name')->get()
            ->filter(fn (self $i) => $i->appliesTo($categoryId))->values();
    }

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
