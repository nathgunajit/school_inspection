<?php

namespace App\Services;

use App\Enums\UserLevel;
use App\Models\Holiday;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * V14 item 3: state and district (local) holidays plus the weekly off days.
 * A district sees the state holidays and its own; the State sees state holidays only.
 * Lookups are cached per year and district, with a version key bumped whenever a holiday is saved or deleted.
 */
class HolidayCalendar
{
    private const VERSION_KEY = 'holidays:version';

    /** Bump the cache version (called from the Holiday model events). */
    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 0) + 1);
    }

    /** The district an officer's holidays follow (null for state-wide officers). */
    public function districtOf(User $user): ?int
    {
        if ($user->level->isStateWide()) {
            return null;
        }
        $j = app(JurisdictionService::class)->current($user);

        return $j?->district_id ? (int) $j->district_id : null;
    }

    /**
     * Y-m-d => holiday names, for one year and district (state holidays included). Only the days off (gazetted):
     * restricted and half holidays never block a plan or the daily list.
     */
    public function year(int $year, ?int $districtId): array
    {
        $key = 'holidays:v2:'.Cache::get(self::VERSION_KEY, 0).":$year:".($districtId ?? 'state');

        return Cache::remember($key, 3600, fn () => $this->area(Holiday::query(), $districtId)
            ->whereIn('type', Holiday::OFF_TYPES)
            ->whereBetween('date', ["$year-01-01", "$year-12-31"])
            ->orderBy('date')->orderByRaw('district_id is not null')->get(['date', 'name'])
            ->groupBy('date')->map(fn ($g) => $g->pluck('name')->all())->all());
    }

    /** Every holiday (all types) between two dates for display: state rows plus the district's. */
    public function between(CarbonInterface|string $from, CarbonInterface|string $to, ?int $districtId)
    {
        $f = $from instanceof CarbonInterface ? $from->toDateString() : $from;
        $t = $to instanceof CarbonInterface ? $to->toDateString() : $to;

        return $this->area(Holiday::query(), $districtId)->whereBetween('date', [$f, $t])
            ->orderBy('date')->orderByRaw('district_id is not null')->orderBy('name')->get();
    }

    /** The next $n holidays from today (all types). */
    public function upcoming(?int $districtId, int $n = 6)
    {
        return $this->area(Holiday::query(), $districtId)->where('date', '>=', today()->toDateString())
            ->orderBy('date')->orderByRaw("case when type = 'gazetted' then 0 when type = 'half' then 1 else 2 end")
            ->orderBy('name')->take($n)->get();
    }

    private function area($q, ?int $districtId)
    {
        return $q->where(fn ($w) => $w->whereNull('district_id')->when($districtId, fn ($x) => $x->orWhere('district_id', $districtId)));
    }

    /** The holiday name(s) on a date, or null. */
    public function holiday(CarbonInterface|string $date, ?int $districtId): ?string
    {
        $d = $date instanceof CarbonInterface ? $date : Carbon::parse($date);
        $names = $this->year((int) $d->year, $districtId)[$d->toDateString()] ?? null;

        return $names ? implode(', ', $names) : null;
    }

    public function isHoliday(CarbonInterface|string $date, ?int $districtId): bool
    {
        return $this->holiday($date, $districtId) !== null;
    }

    /** Weekly off days (0 = Sunday … 6 = Saturday). */
    public function weeklyOff(?array $override = null): array
    {
        return array_map('intval', $override ?? config('inspection.holidays.weekly_off', [0]));
    }

    public function isWorkingDay(CarbonInterface|string $date, ?int $districtId, ?array $weeklyOff = null): bool
    {
        $d = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return ! in_array((int) $d->dayOfWeek, $this->weeklyOff($weeklyOff), true) && ! $this->isHoliday($d, $districtId);
    }

    /** Why a date is not a working day ("Sunday", "Holiday: Bihu"), or null when it is one. */
    public function offReason(CarbonInterface|string $date, ?int $districtId, ?array $weeklyOff = null): ?string
    {
        $d = $date instanceof CarbonInterface ? $date : Carbon::parse($date);
        if ($name = $this->holiday($d, $districtId)) {
            return 'Holiday: '.$name;
        }

        return in_array((int) $d->dayOfWeek, $this->weeklyOff($weeklyOff), true) ? 'Weekly off ('.$d->format('l').')' : null;
    }

    /** The last working day before $date (at most 60 days back), or null. */
    public function previousWorkingDay(CarbonInterface $date, ?int $districtId, ?array $weeklyOff = null): ?Carbon
    {
        $d = Carbon::parse($date->toDateString());
        for ($i = 0; $i < 60; $i++) {
            $d->subDay();
            if ($this->isWorkingDay($d, $districtId, $weeklyOff)) {
                return $d;
            }
        }

        return null;
    }

    public function nextWorkingDay(CarbonInterface $date, ?int $districtId, ?array $weeklyOff = null): ?Carbon
    {
        $d = Carbon::parse($date->toDateString());
        for ($i = 0; $i < 60; $i++) {
            $d->addDay();
            if ($this->isWorkingDay($d, $districtId, $weeklyOff)) {
                return $d;
            }
        }

        return null;
    }

    /** May this user add / change / delete this holiday? State rows: State Admin only; district rows: State Admin or that district. */
    public function canManage(User $user, ?int $districtId): bool
    {
        if (! $user->can('manage_holidays')) {
            return false;
        }
        if ($user->isStateAdmin()) {
            return true;
        }

        return $districtId !== null && $user->level === UserLevel::District && $this->districtOf($user) === $districtId;
    }

    /** 25-11-2026: districts this user may add local holidays for (State Admin: all active; a District manager: their own). */
    public function editableDistricts(User $user)
    {
        if ($user->isStateAdmin()) {
            return \App\Models\District::where('is_active', true)->orderBy('code')->get(['id', 'name', 'code']);
        }
        $own = $this->districtOf($user);

        return $own && $user->can('manage_holidays') ? \App\Models\District::whereKey($own)->get(['id', 'name', 'code']) : collect();
    }

    /**
     * 25-11-2026: the day popup data for a calendar cell (holidays/partials/day-modal, holiday-day.js).
     *
     * @param  iterable<Holiday>  $holidays
     */
    public function dayData(User $user, CarbonInterface $day, iterable $holidays, bool $weeklyOff): array
    {
        return [
            'date' => $day->toDateString(),
            'label' => $day->format('l, j F Y'),
            'off' => $weeklyOff,
            'holidays' => collect($holidays)->map(fn (Holiday $h) => [
                'name' => $h->name, 'type' => $h->typeLabel(), 'chip' => $h->chipClass(), 'area' => $h->areaLabel(),
                'remove' => $this->canManage($user, $h->district_id) ? route('holidays.destroy', $h) : null,
            ])->values()->all(),
        ];
    }
}
