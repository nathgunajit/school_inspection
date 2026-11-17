<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MasterValue extends Model
{
    protected $fillable = ['type', 'code', 'label', 'sort', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeOfType(Builder $q, string $type): Builder
    {
        return $q->where('type', $type)->where('is_active', true)->orderBy('sort')->orderBy('label');
    }

    public static function idFor(string $type, string $code): ?int
    {
        return static::where('type', $type)->where('code', $code)->value('id');
    }
}
