<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Model;

/** 27-11-2026: a state holiday (one day) that does not apply in one district; that district works on that day. */
class HolidayWithdrawal extends Model
{
    use HasEncryptedRouteKey;

    protected $fillable = ['holiday_id', 'district_id', 'reason', 'created_by'];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\HolidayCalendar::flush());
        static::deleted(fn () => \App\Services\HolidayCalendar::flush());
    }

    public function holiday() { return $this->belongsTo(Holiday::class); }
    public function district() { return $this->belongsTo(District::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
