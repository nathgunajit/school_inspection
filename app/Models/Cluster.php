<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cluster extends Model
{
    use Concerns\HasCodeLabel;

    protected $fillable = ['block_id', 'code', 'name', 'latitude', 'longitude', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'latitude' => 'float', 'longitude' => 'float'];

    public function block()
    {
        return $this->belongsTo(Block::class);
    }

    public function schools()
    {
        return $this->hasMany(School::class);
    }
}
