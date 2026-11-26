<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\DailySchoolListService;
use App\Services\HolidayCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Modification V15 item 8 (26-11-2026): the Today's Schools settings changed, so every list already made for today or
 * ahead of the day is brought in line at once, without waiting for the officer to open it.
 *  - today: generate() sees the new stamp and refreshes (what the officer acted on stays; a locked list is not touched);
 *  - a later day made ahead (next-day notice): made again (assigned rows stay).
 * $districtId null = the state default changed (every officer); otherwise only that district's officers.
 */
class RefreshDailyLists implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public ?int $districtId = null) {}

    public function handle(DailySchoolListService $lists, HolidayCalendar $calendar): void
    {
        $today = today()->toDateString();
        DB::table('daily_school_list_days')->where('list_date', '>=', $today)->orderBy('officer_id')->orderBy('list_date')
            ->get(['officer_id', 'list_date', 'locked_at', 'unlocked_at'])->groupBy('officer_id')
            ->chunk(200)->each(function ($chunk) use ($lists, $calendar, $today) {
                $officers = User::whereIn('id', $chunk->keys())->with('currentJurisdiction')->get()->keyBy('id');
                foreach ($chunk as $officerId => $days) {
                    $officer = $officers->get($officerId);
                    if (! $officer || ($this->districtId && $calendar->districtOf($officer) !== $this->districtId)) {
                        continue;
                    }
                    foreach ($days as $d) {
                        $day = substr((string) $d->list_date, 0, 10);
                        try {
                            if ($day === $today) {
                                $setting = $lists->settingFor($officer);
                                if ($lists->appliesTo($officer, $setting)) {
                                    $lists->generate($officer, today(), $setting);
                                }
                            } elseif (! $d->locked_at) {
                                $lists->rebuildAhead($officer, Carbon::parse($day));
                            }
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    }
                }
            });
    }
}
