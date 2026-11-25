<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * V14 item 5: Today's schools settings. district_id null = state default; a district row overrides it
 * for that district (the same pattern as CoverageSetting).
 */
class DailyDisplaySetting extends Model
{
    public const SELECTIONS = ['priority' => 'By priority (the options below)', 'random' => 'Random from the eligible schools'];

    protected $fillable = ['district_id', 'enabled', 'levels', 'daily_limit', 'selection', 'prefer_not_inspected', 'prefer_low_rating', 'low_rating_below',
        'prefer_issues', 'prefer_less_visited', 'carry_forward', 'recent_days', 'weekly_off', 'skip_blocked', 'max_distance_km', 'updated_by'];

    protected $casts = ['enabled' => 'boolean', 'levels' => 'array', 'weekly_off' => 'array', 'daily_limit' => 'integer', 'low_rating_below' => 'integer',
        'prefer_not_inspected' => 'boolean', 'prefer_low_rating' => 'boolean', 'prefer_issues' => 'boolean', 'prefer_less_visited' => 'boolean',
        'carry_forward' => 'boolean', 'recent_days' => 'integer', 'skip_blocked' => 'boolean', 'max_distance_km' => 'integer'];

    public function district() { return $this->belongsTo(District::class); }
    public function updater() { return $this->belongsTo(User::class, 'updated_by'); }

    public static function defaults(): array
    {
        return ['enabled' => true, 'levels' => ['cluster'], 'daily_limit' => 2, 'selection' => 'priority', 'prefer_not_inspected' => true,
            'prefer_low_rating' => true, 'low_rating_below' => 50, 'prefer_issues' => true, 'prefer_less_visited' => true, 'carry_forward' => true,
            'recent_days' => 30, 'weekly_off' => [0], 'skip_blocked' => true, 'max_distance_km' => null];
    }

    public static function state(): self
    {
        return static::whereNull('district_id')->first() ?? static::create(['district_id' => null] + self::defaults());
    }

    public static function forDistrict(?int $districtId): self
    {
        return ($districtId ? static::where('district_id', $districtId)->first() : null) ?? static::state();
    }
}
