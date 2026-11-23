<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Model;

/** AC master: Assembly constituency, part of one PC. Never deleted, only made inactive. */
class AssemblyConstituency extends Model
{
    use HasEncryptedRouteKey;

    protected $fillable = ['code', 'name', 'pc_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function pc() { return $this->belongsTo(ParliamentaryConstituency::class, 'pc_id'); }
    public function schools() { return $this->hasMany(School::class, 'ac_id'); }

    public function scopeActive($q) { return $q->where('is_active', true); }

    /** "DOTMA (ST) · AC18002" */
    public function getLabelAttribute(): string
    {
        return $this->name.' · '.$this->code;
    }
}
