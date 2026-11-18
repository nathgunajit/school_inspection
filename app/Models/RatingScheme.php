<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A versioned scoring set-up for the automatic inspection rating (New Module 3). */
class RatingScheme extends Model
{
    protected $fillable = ['version', 'name', 'is_active', 'bands', 'created_by', 'activated_at'];

    protected $casts = ['is_active' => 'boolean', 'bands' => 'array', 'activated_at' => 'datetime'];

    public function criteria() { return $this->hasMany(RatingCriterion::class, 'scheme_id')->orderBy('sort'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
