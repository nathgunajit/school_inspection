<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class District extends Model
{
    protected $fillable = ['state_id', 'code', 'name', 'latitude', 'longitude', 'boundary_name', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'latitude' => 'float', 'longitude' => 'float'];

    public function state()
    {
        return $this->belongsTo(State::class);
    }

    public function blocks()
    {
        return $this->hasMany(Block::class);
    }

    public function schools()
    {
        return $this->hasMany(School::class);
    }
}
