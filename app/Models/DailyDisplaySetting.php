<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * V14 item 5: Today's schools settings. district_id null = state default; a district row overrides it
 * for that district (the same pattern as CoverageSetting).
 */
class DailyDisplaySetting extends Model
{
    /** Officer levels a district setting may choose; the others come from the state default (25-11-2026). */
    public const DISTRICT_LEVELS = ['cluster', 'block'];
    public const STATE_ONLY_LEVELS = ['district', 'state'];

    /** V15 (26-11-2026): set by the State Administrator for every user; a district row always takes these from the state row. */
    public const STATE_ONLY_FIELDS = ['allow_skip', 'notify_enabled', 'notify_time', 'reminder_enabled', 'reminder_time'];

    /** 26-11-2026: how long a school inspected by another officer stays off the list (skip_blocked). */
    public const SKIP_WINDOWS = ['day' => 'Same day', 'week' => 'This week (Monday to Sunday)', 'month' => 'This month'];

    public const SELECTIONS = ['priority' => 'By priority (the options below)', 'random' => 'Random from the eligible schools'];

    protected $fillable = ['district_id', 'enabled', 'levels', 'daily_limit', 'selection', 'prefer_not_inspected', 'prefer_low_rating', 'low_rating_below',
        'prefer_issues', 'prefer_less_visited', 'carry_forward', 'recent_days', 'weekly_off', 'skip_blocked', 'skip_window', 'max_distance_km', 'updated_by',
        'allow_skip', 'notify_enabled', 'notify_time', 'reminder_enabled', 'reminder_time'];

    protected $casts = ['enabled' => 'boolean', 'levels' => 'array', 'weekly_off' => 'array', 'daily_limit' => 'integer', 'low_rating_below' => 'integer',
        'prefer_not_inspected' => 'boolean', 'prefer_low_rating' => 'boolean', 'prefer_issues' => 'boolean', 'prefer_less_visited' => 'boolean',
        'carry_forward' => 'boolean', 'recent_days' => 'integer', 'skip_blocked' => 'boolean', 'max_distance_km' => 'integer',
        'allow_skip' => 'boolean', 'notify_enabled' => 'boolean', 'reminder_enabled' => 'boolean'];

    public function district() { return $this->belongsTo(District::class); }
    public function updater() { return $this->belongsTo(User::class, 'updated_by'); }

    public static function defaults(): array
    {
        return ['enabled' => true, 'levels' => ['cluster'], 'daily_limit' => 2, 'selection' => 'priority', 'prefer_not_inspected' => true,
            'prefer_low_rating' => true, 'low_rating_below' => 50, 'prefer_issues' => true, 'prefer_less_visited' => true, 'carry_forward' => true,
            'recent_days' => 30, 'weekly_off' => [0], 'skip_blocked' => true, 'skip_window' => 'month', 'max_distance_km' => null,
            'allow_skip' => true, 'notify_enabled' => true, 'notify_time' => '18:00', 'reminder_enabled' => true, 'reminder_time' => '14:00'];
    }

    public static function state(): self
    {
        return static::whereNull('district_id')->first() ?? static::create(['district_id' => null] + self::defaults());
    }

    protected static function booted(): void
    {
        $forget = fn () => \App\Support\RequestMemo::forget('daily_display.');
        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * 26-11-2026: read once per request (the dashboard asked three times, two queries each), like
     * CoverageSetting::forDistrict(). Each caller gets its own copy, so changing it in memory never leaks.
     */
    public static function forDistrict(?int $districtId): self
    {
        return clone \App\Support\RequestMemo::remember('daily_display.'.($districtId ?: 0), fn () => static::resolveForDistrict($districtId));
    }

    private static function resolveForDistrict(?int $districtId): self
    {
        $row = $districtId ? static::where('district_id', $districtId)->first() : null;
        if (! $row) {
            return static::state();
        }
        // 25-11-2026: a district chooses only its Cluster / Block officers; District / State officers always follow the State.
        // Null levels = no own choice yet, so Cluster / Block follow the State too. Filled in memory, never saved.
        $stateRow = static::state();
        $state = $stateRow->levels ?? [];
        $raw = $row->getRawOriginal('levels');
        $own = array_intersect($raw === null ? $state : ($row->levels ?? []), self::DISTRICT_LEVELS);
        $row->levels = array_values(array_unique([...$own, ...array_intersect($state, self::STATE_ONLY_LEVELS)]));
        foreach (self::STATE_ONLY_FIELDS as $f) { // V15: Skip and the notices are the State's for everyone
            $row->{$f} = $stateRow->{$f};
        }

        return $row;
    }

    /**
     * 26-11-2026: a fingerprint of the values that decide which schools are on a list. A day's list made with another
     * stamp is refreshed on the officer's next view (DailySchoolListService::refresh()), so a settings change shows the
     * same day. enabled / levels / weekly_off are not in it: they are checked on every view anyway.
     */
    public function listStamp(): string
    {
        return sha1(json_encode([(int) $this->daily_limit, (string) $this->selection, (bool) $this->prefer_not_inspected, (bool) $this->prefer_low_rating,
            (int) $this->low_rating_below, (bool) $this->prefer_issues, (bool) $this->prefer_less_visited, (bool) $this->carry_forward,
            (int) $this->recent_days, (bool) $this->skip_blocked, (string) ($this->skip_window ?: 'month'), $this->max_distance_km === null ? null : (int) $this->max_distance_km]));
    }

    /** A district row whose officer levels come from the state default. */
    public function followsStateLevels(): bool
    {
        return $this->district_id !== null && $this->getRawOriginal('levels') === null;
    }
}
