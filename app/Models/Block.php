<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Block extends Model
{
    use Concerns\HasCodeLabel;

    protected $fillable = ['district_id', 'code', 'name', 'latitude', 'longitude', 'office_address', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'latitude' => 'float', 'longitude' => 'float'];

    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function clusters()
    {
        return $this->hasMany(Cluster::class);
    }

    public function schools()
    {
        return $this->hasMany(School::class);
    }
}
