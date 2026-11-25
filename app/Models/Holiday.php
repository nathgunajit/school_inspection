<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Model;

/** V14 item 3: a state holiday (district_id null) or a local holiday of one district. */
class Holiday extends Model
{
    use HasEncryptedRouteKey;

    protected $fillable = ['date', 'name', 'district_id', 'created_by'];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\HolidayCalendar::flush());
        static::deleted(fn () => \App\Services\HolidayCalendar::flush());
    }

    public function district() { return $this->belongsTo(District::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    /** Stored as plain Y-m-d, like inspection_date and schedule_date. */
    public function getDateAttribute($value): ?string
    {
        return $value ? substr((string) $value, 0, 10) : null;
    }

    public function isState(): bool
    {
        return $this->district_id === null;
    }

    public function areaLabel(): string
    {
        return $this->isState() ? 'State holiday' : 'Local · '.($this->district?->label ?? 'District');
    }
}
