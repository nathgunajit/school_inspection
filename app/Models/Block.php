<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Block extends Model
{
    protected $fillable = ['district_id', 'code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

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
