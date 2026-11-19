<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** V6 Module 6: one sign-in attempt, and for a successful one the session's activity and sign-out. */
class UserLoginLog extends Model
{
    public const STATUSES = ['success' => 'Signed in', 'failed' => 'Failed', 'locked' => 'Locked', 'denied' => 'Not allowed'];

    public $timestamps = false;

    protected $fillable = ['user_id', 'mobile', 'status', 'reason', 'session_id', 'ip_address', 'user_agent', 'browser', 'platform', 'device',
        'login_at', 'last_seen_at', 'logout_at', 'logout_reason'];

    protected $casts = ['login_at' => 'datetime', 'last_seen_at' => 'datetime', 'logout_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** online, signed_out or timed_out for a successful sign-in; null for a refused attempt. */
    public function sessionState(): ?string
    {
        if ($this->status !== 'success') {
            return null;
        }
        if ($this->logout_at) {
            return 'signed_out';
        }

        return ($this->last_seen_at ?? $this->login_at)->gt(now()->subMinutes((int) config('session.lifetime'))) ? 'online' : 'timed_out';
    }

    /** Session length in minutes: until sign-out, or until the last activity. */
    public function durationMinutes(): ?int
    {
        if ($this->status !== 'success') {
            return null;
        }
        $end = $this->logout_at ?? $this->last_seen_at ?? $this->login_at;

        return (int) round($this->login_at->diffInSeconds($end, true) / 60);
    }

    public static function durationLabel(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        return $minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).' h '.($minutes % 60).' min';
    }
}
