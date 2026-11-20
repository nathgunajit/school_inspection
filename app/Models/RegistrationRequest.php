<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use App\Services\ApproverResolver;
use Illuminate\Database\Eloquent\Model;

class RegistrationRequest extends Model
{
    use HasEncryptedRouteKey;

    protected $fillable = ['user_id', 'approver_id', 'status', 'reason', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime'];

    protected static function booted(): void
    {
        // New, re-applied, approved or rejected: refresh every approver's badge
        static::saved(fn () => ApproverResolver::flushPendingCounts());
        static::deleted(fn () => ApproverResolver::flushPendingCounts());
    }

    public function user() { return $this->belongsTo(User::class)->withTrashed(); }
    public function approver() { return $this->belongsTo(User::class, 'approver_id'); }
}
