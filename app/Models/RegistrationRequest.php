<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationRequest extends Model
{
    protected $fillable = ['user_id', 'approver_id', 'status', 'reason', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class)->withTrashed(); }
    public function approver() { return $this->belongsTo(User::class, 'approver_id'); }
}
