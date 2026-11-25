<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Model;

/** V14 item 3: a state holiday (district_id null) or a local holiday of one district. */
class Holiday extends Model
{
    use HasEncryptedRouteKey;

    /** 25-11-2026: only gazetted holidays close offices; restricted and half holidays are shown only. */
    public const TYPES = ['gazetted' => 'Holiday', 'restricted' => 'Restricted holiday', 'half' => 'Half holiday (from 1 PM)'];

    public const OFF_TYPES = ['gazetted'];

    protected $fillable = ['date', 'name', 'type', 'district_id', 'created_by'];

    protected $attributes = ['type' => 'gazetted'];

    public function isOff(): bool
    {
        return in_array($this->type ?? 'gazetted', self::OFF_TYPES, true);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type ?? 'gazetted'] ?? 'Holiday';
    }

    /** CSS modifier for the chips: state / local (gazetted), restricted, half. */
    public function chipClass(): string
    {
        return match ($this->type) {
            'restricted' => 'is-restricted',
            'half' => 'is-half',
            default => $this->isState() ? 'is-state' : 'is-local',
        };
    }

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
