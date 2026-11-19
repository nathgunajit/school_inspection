<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Model;

class MobileChangeRequest extends Model
{
    use HasEncryptedRouteKey;

    protected $fillable = ['user_id', 'old_mobile', 'new_mobile', 'status', 'reason', 'decided_by', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
}
