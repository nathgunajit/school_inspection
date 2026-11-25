<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Coverage period setting (Inspection Coverage Report). district_id null = state default;
 * a district row overrides the period and cycle for that district. The verification radius
 * always comes from the state row.
 */
class CoverageSetting extends Model
{
    public const PERIODS = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'custom' => 'Custom date range'];

    public const CYCLES = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Half-yearly', 'yearly' => 'Yearly'];

    protected $fillable = ['district_id', 'period_type', 'custom_from', 'custom_to', 'inspection_cycle', 'verify_radius_m', 'updated_by'];

    protected $casts = ['custom_from' => 'date', 'custom_to' => 'date', 'verify_radius_m' => 'integer'];

    protected static function booted(): void
    {
        $forget = fn () => \App\Support\RequestMemo::forget('coverage.');
        static::saved($forget);
        static::deleted($forget);
    }

    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The state default row (created with config defaults if it is missing). */
    public static function state(): self
    {
        return static::whereNull('district_id')->first() ?? static::create([
            'district_id' => null,
            'period_type' => config('inspection.coverage.period_type', 'monthly'),
            'inspection_cycle' => config('inspection.coverage.inspection_cycle', 'quarterly'),
            'verify_radius_m' => config('inspection.coverage.verify_radius_m', 500),
        ]);
    }

    /** The setting that applies to a district: its own row, else the state default. */
    public static function forDistrict(?int $districtId): self
    {
        // 25-11-2026: one query per district and request (the coverage page asked up to 9 times)
        return \App\Support\RequestMemo::remember('coverage.'.($districtId ?: 0), fn () => ($districtId ? static::where('district_id', $districtId)->first() : null) ?? static::state());
    }

    public function periodLabel(): string
    {
        return self::PERIODS[$this->period_type] ?? ucfirst($this->period_type);
    }

    public function cycleLabel(): string
    {
        return self::CYCLES[$this->inspection_cycle] ?? ucfirst($this->inspection_cycle);
    }
}
