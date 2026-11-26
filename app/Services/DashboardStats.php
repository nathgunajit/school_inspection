<?php

namespace App\Services;

use App\Enums\InspectionStatus;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * 26-11-2026 performance: the six dashboard figures, cached per officer for a minute.
 *
 * The key carries a version that any inspection or school change bumps (model events, School Master import), plus
 * the day and the officer's current posting, so a submit, a transfer or midnight shows new figures at once.
 */
class DashboardStats
{
    public const VERSION_KEY = 'dashboard:stats:version';

    public const TTL = 60;

    public function __construct(private JurisdictionService $j) {}

    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 0) + 1);
    }

    /** @return array{today:int, month:int, total:int, my_drafts:int, schools:int, schools_inspected_month:int} */
    public function for(User $user): array
    {
        $posting = $this->j->current($user)?->id ?? 0;
        $key = 'dashboard:stats:'.Cache::get(self::VERSION_KEY, 0).':'.$user->id.':'.$posting.':'.today()->toDateString();

        return Cache::remember($key, self::TTL, fn () => $this->compute($user));
    }

    private function compute(User $user): array
    {
        $mine = fn () => $user->inspections()->where('status', InspectionStatus::Submitted);
        $monthStart = now()->startOfMonth()->toDateString();

        return [
            'today' => $mine()->where('inspection_date', today()->toDateString())->count(),
            'month' => $mine()->where('inspection_date', '>=', $monthStart)->count(),
            'total' => $mine()->count(),
            'my_drafts' => $user->inspections()->whereIn('status', [InspectionStatus::Draft, InspectionStatus::Unlocked])->count(),
            // Schools in the officer's own area (cluster / block / district / state), scoped by JurisdictionService
            'schools' => $this->j->schools($user)->where('schools.status', 'active')->count(),
            'schools_inspected_month' => $this->j->inspections($user)
                ->where('inspections.status', InspectionStatus::Submitted)
                ->where('inspections.inspection_date', '>=', $monthStart)
                ->distinct()->count('inspections.school_id'),
        ];
    }
}
