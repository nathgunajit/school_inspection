<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Model;

/** PC master: Parliamentary (Lok Sabha) constituency. Never deleted, only made inactive. */
class ParliamentaryConstituency extends Model
{
    use HasEncryptedRouteKey;

    protected $fillable = ['code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function acs() { return $this->hasMany(AssemblyConstituency::class, 'pc_id'); }
    public function schools() { return $this->hasMany(School::class, 'pc_id'); }

    public function scopeActive($q) { return $q->where('is_active', true); }

    /** "KOKRAJHAR (ST) · PC1801" */
    public function getLabelAttribute(): string
    {
        return $this->name.' · '.$this->code;
    }
}
