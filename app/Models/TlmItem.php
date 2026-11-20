<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** FLN TLM master: items ticked on a classroom observation when TLM was used (step 4). */
class TlmItem extends Model
{
    use HasEncryptedRouteKey;

    protected $fillable = ['class', 'name', 'code', 'variety', 'remarks', 'sort', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'variety' => 'integer', 'sort' => 'integer'];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Active items offered for a class: that class's items plus the "All classes" ones. */
    public function scopeForClass(Builder $q, ?string $class): Builder
    {
        return $q->active()->where(fn ($w) => $w->where('class', $class)->orWhereNull('class'))->orderBy('sort')->orderBy('name');
    }

    public function classLabel(): string
    {
        return $this->class === null ? 'All classes' : (InspectionClassroomObservation::CLASSES[$this->class] ?? $this->class);
    }

    /** Name with its code, e.g. "Karjyapatra (Literacy) · NIPUN AXOM G1L4". */
    public function fullLabel(): string
    {
        return $this->name.($this->code ? ' · '.$this->code : '');
    }

    public function toPickArray(): array
    {
        return ['id' => $this->id, 'class' => $this->class, 'name' => $this->name, 'code' => $this->code];
    }
}
