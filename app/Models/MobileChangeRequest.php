<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileChangeRequest extends Model
{
    protected $fillable = ['user_id', 'old_mobile', 'new_mobile', 'status', 'reason', 'decided_by', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
}
