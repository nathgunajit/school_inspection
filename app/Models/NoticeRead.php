<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** V14 item 1: when an officer opened and acknowledged a notice. */
class NoticeRead extends Model
{
    protected $fillable = ['notice_id', 'user_id', 'read_at', 'acknowledged_at'];

    protected $casts = ['read_at' => 'datetime', 'acknowledged_at' => 'datetime'];

    public function notice() { return $this->belongsTo(Notice::class); }
    public function user() { return $this->belongsTo(User::class); }
}
