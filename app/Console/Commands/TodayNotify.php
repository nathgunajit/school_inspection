<?php

namespace App\Console\Commands;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\DailyDisplaySetting;
use App\Models\User;
use App\Notifications\NextDaySchools;
use App\Notifications\PendingSchoolsReminder;
use App\Services\AuditLogger;
use App\Services\DailySchoolListService;
use App\Services\HolidayCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Modification V15 items 6–7 (26-11-2026), scheduled every five minutes:
 *  - from the State Administrator's notice time, each officer who gets Today's Schools receives the list of their next
 *    working day (made ahead now, DailySchoolListService::prepare()), once (`daily_school_list_days.notified_at`);
 *  - from the reminder time on a working day, each officer with schools still pending today gets a reminder, once
 *    (`reminded_at`).
 * In-app alerts only (the bell). Holidays and weekly offs come from the officer's district calendar.
 */
class TodayNotify extends Command
{
    protected $signature = 'today:notify {--now= : Run as if it were this time (Y-m-d H:i), for checks} {--force : Ignore the times}';

    protected $description = "Today's Schools: send the next-day list notice and the pending reminder (V15)";

    public function handle(DailySchoolListService $lists, HolidayCalendar $calendar): int
    {
        if ($this->option('now')) {
            Carbon::setTestNow(Carbon::parse($this->option('now')));
        }
        $state = DailyDisplaySetting::state();
        $time = now()->format('H:i');
        $force = (bool) $this->option('force');
        $doNotice = $state->notify_enabled && ($force || $time >= $state->notify_time);
        $doRemind = $state->reminder_enabled && ($force || $time >= $state->reminder_time);
        $today = today()->toDateString();
        $flag = fn ($k) => "today-notify:$k:$today";
        $doNotice = $doNotice && ($force || ! Cache::has($flag('notice')));
        $doRemind = $doRemind && ($force || ! Cache::has($flag('remind')));
        if (! $doNotice && ! $doRemind) {
            $this->line('Nothing to send now.');

            return self::SUCCESS;
        }

        $sent = ['notice' => 0, 'remind' => 0];
        User::where('status', UserStatus::Active)->where('level', '!=', UserLevel::StateAdmin->value)
            ->with('currentJurisdiction')->orderBy('id')
            ->chunkById(200, function ($users) use ($lists, $calendar, $doNotice, $doRemind, $today, &$sent) {
                foreach ($users as $officer) {
                    try {
                        $setting = $lists->settingFor($officer);
                        if (! $lists->appliesTo($officer, $setting)) {
                            continue;
                        }
                        $district = $calendar->districtOf($officer);
                        if ($doNotice && ($next = $calendar->nextWorkingDay(today(), $district, $setting->weekly_off))) {
                            $sent['notice'] += (int) $this->notice($lists, $officer, $next);
                        }
                        if ($doRemind && $calendar->isWorkingDay(today(), $district, $setting->weekly_off)) {
                            $sent['remind'] += (int) $this->remind($lists, $officer, $today);
                        }
                    } catch (\Throwable $e) {
                        report($e);
                        $this->warn("Officer {$officer->id}: ".$e->getMessage());
                    }
                }
            });

        foreach (['notice' => $doNotice, 'remind' => $doRemind] as $k => $done) {
            if ($done && ! $this->option('force')) {
                Cache::put("today-notify:$k:$today", true, now()->endOfDay());
            }
        }
        if ($sent['notice'] || $sent['remind']) {
            AuditLogger::log('today_notifications_sent', null, ['date' => $today, 'next_day' => $sent['notice'], 'reminders' => $sent['remind']]);
        }
        $this->info("Next-day notices: {$sent['notice']} · pending reminders: {$sent['remind']}");

        return self::SUCCESS;
    }

    private function notice(DailySchoolListService $lists, User $officer, \Carbon\CarbonInterface $day): bool
    {
        $d = $day->toDateString();
        if ($lists->dayMarker($officer, $d)?->notified_at) {
            return false;
        }
        $rows = $lists->prepare($officer, $day);
        if ($rows === null || $rows->isEmpty()) {
            return false;
        }
        $carried = $rows->where('source', 'carried')->count();
        $officer->notify(new NextDaySchools($d, $carried, $rows->count() - $carried, $rows->map(fn ($r) => $r->school?->name)->filter()->values()->all()));
        DB::table('daily_school_list_days')->where('officer_id', $officer->id)->where('list_date', $d)->update(['notified_at' => now()]);

        return true;
    }

    private function remind(DailySchoolListService $lists, User $officer, string $today): bool
    {
        if ($lists->dayMarker($officer, $today)?->reminded_at) {
            return false;
        }
        $list = $lists->forOfficer($officer, today()); // makes today's list when the officer has not opened it yet
        $pending = $list['state'] === 'list' ? $list['rows']->where('status', 'pending') : collect();
        if ($pending->isEmpty()) {
            return false;
        }
        $officer->notify(new PendingSchoolsReminder($today, $pending->map(fn ($r) => $r->school?->name)->filter()->values()->all()));
        DB::table('daily_school_list_days')->where('officer_id', $officer->id)->where('list_date', $today)->update(['reminded_at' => now()]);

        return true;
    }
}
