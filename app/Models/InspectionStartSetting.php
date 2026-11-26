<?php

namespace App\Models;

use App\Support\RequestMemo;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 26-11-2026: inspection start rules set by the State Admin (Admin Settings → Inspection Rules), one row.
 * Read by InspectionStartGuard, ScheduleService::blockedSchools() and the Find School badges.
 */
class InspectionStartSetting extends Model
{
    /** How long a school stays blocked for the same level after a submitted inspection. */
    public const WINDOWS = [
        'month' => 'This calendar month',
        'week' => 'This week (Monday to Sunday)',
        'day' => 'This day only',
        'none' => 'None (no block after a submitted inspection)',
    ];

    protected $fillable = ['repeat_window', 'cross_level', 'updated_by'];

    protected $casts = ['cross_level' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(fn () => RequestMemo::forget('inspection-start'));
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The setting row (created with the defaults when missing), once per request. */
    public static function current(): self
    {
        return RequestMemo::remember('inspection-start', fn () => static::query()->first()
            ?? static::create(['repeat_window' => 'month', 'cross_level' => true]));
    }

    /** [from, to] of the block window that contains $date, or null for "none". */
    public function window(CarbonInterface $date): ?array
    {
        $d = Carbon::instance($date)->startOfDay();

        return match ($this->repeat_window) {
            'none' => null,
            'day' => [$d, $d->copy()],
            'week' => [$d->copy()->startOfWeek(Carbon::MONDAY), $d->copy()->startOfWeek(Carbon::MONDAY)->addDays(6)],
            default => [$d->copy()->startOfMonth(), $d->copy()->endOfMonth()->startOfDay()],
        };
    }

    /** First day on which a school blocked on $date is open again (null for "none"). */
    public function reopensOn(CarbonInterface $date): ?Carbon
    {
        return ($w = $this->window($date)) ? $w[1]->copy()->addDay() : null;
    }

    /** "this month", "this week", "today" (null for "none"). */
    public function windowLabel(): ?string
    {
        return ['month' => 'this month', 'week' => 'this week', 'day' => 'today'][$this->repeat_window] ?? null;
    }

    public function label(): string
    {
        return self::WINDOWS[$this->repeat_window] ?? self::WINDOWS['month'];
    }
}
