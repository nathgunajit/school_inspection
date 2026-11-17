<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cluster extends Model
{
    protected $fillable = ['block_id', 'code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function block()
    {
        return $this->belongsTo(Block::class);
    }

    public function schools()
    {
        return $this->hasMany(School::class);
    }
}
