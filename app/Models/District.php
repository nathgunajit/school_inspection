<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class District extends Model
{
    use Concerns\HasCodeLabel;

    protected $fillable = ['state_id', 'code', 'name', 'latitude', 'longitude', 'office_address', 'boundary_name', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'latitude' => 'float', 'longitude' => 'float'];

    public function state()
    {
        return $this->belongsTo(State::class);
    }

    public function blocks()
    {
        return $this->hasMany(Block::class);
    }

    /** Clusters of the district, through its blocks (Master downloads count them). */
    public function clusters()
    {
        return $this->hasManyThrough(Cluster::class, Block::class);
    }

    public function schools()
    {
        return $this->hasMany(School::class);
    }
}
