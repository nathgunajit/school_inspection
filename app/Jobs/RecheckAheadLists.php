<?php

namespace App\Jobs;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\DailySchoolListService;
use App\Services\HolidayCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * 28-11-2026: a holiday of the state ($districtId null) or of one district was added, changed, removed or withdrawn.
 * Lists made ahead for a day that is no longer a working day are taken back, and the next-day notice goes out for the
 * real next working day (DailySchoolListService::recheckAhead()). Officers whose holidays follow another district are
 * not touched.
 */
class RecheckAheadLists implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public ?int $districtId = null) {}

    public function handle(DailySchoolListService $lists, HolidayCalendar $calendar): void
    {
        // today's notice already went out (today:notify sets this flag), so a new next working day needs its notice now
        $noticeRan = Cache::has('today-notify:notice:'.today()->toDateString());

        User::where('status', UserStatus::Active)->where('level', '!=', UserLevel::StateAdmin->value)
            ->with('currentJurisdiction')->orderBy('id')
            ->chunkById(200, function ($users) use ($lists, $calendar, $noticeRan) {
                foreach ($users as $officer) {
                    if ($this->districtId !== null && $calendar->districtOf($officer) !== $this->districtId) {
                        continue;
                    }
                    try {
                        $lists->recheckAhead($officer, $noticeRan);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });
    }
}
