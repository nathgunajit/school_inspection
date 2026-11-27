<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Http\Middleware\RunDueAlerts;
use App\Jobs\RefreshDailyLists;
use App\Models\Cluster;
use App\Models\DailyDisplaySetting;
use App\Notifications\NextDaySchools;
use App\Support\QueuedExports;
use App\Support\SchedulerHeartbeat;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Modification V16 (27-11-2026): Today's Schools settings refresh and alerts work without a queue worker or scheduler. */
class TodaySchoolsV16Test extends TestCase
{
    private const PAYLOAD = ['scope' => 'state', 'enabled' => 1, 'levels' => ['cluster'], 'daily_limit' => 3, 'selection' => 'priority',
        'low_rating_below' => 50, 'recent_days' => 30, 'carry_forward' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        DailyDisplaySetting::state()->update(['weekly_off' => []]);
        Cache::forget(QueuedExports::HEARTBEAT);
        Cache::forget(SchedulerHeartbeat::KEY);
        Cache::forget(RunDueAlerts::LOCK);
    }

    public function test_settings_refresh_runs_after_the_response_when_no_worker_runs(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();
        $this->actingAs($this->admin())->put(route('today.settings.update'), self::PAYLOAD)->assertRedirect();
        Bus::assertDispatchedAfterResponse(RefreshDailyLists::class);
        $this->assertCount(0, Bus::dispatched(RefreshDailyLists::class), "not left waiting for a worker");
    }

    public function test_settings_refresh_is_queued_when_a_worker_runs(): void
    {
        config(['queue.default' => 'database']);
        QueuedExports::beat();
        Bus::fake();
        $this->actingAs($this->admin())->put(route('today.settings.update'), self::PAYLOAD)->assertRedirect();
        Bus::assertDispatched(RefreshDailyLists::class);
        Bus::assertNotDispatchedAfterResponse(RefreshDailyLists::class);
    }

    public function test_alerts_are_sent_from_page_views_without_the_scheduler(): void
    {
        config(['inspection.daily_list.alert_fallback' => true]);
        $crcc = $this->officer(UserLevel::Cluster, Cluster::orderBy('id')->firstOrFail());
        $this->travelTo(today()->setTime(9, 0));
        $this->actingAs($crcc)->get(route('today.index'))->assertOk(); // before the notice time: nothing to send
        $this->assertSame(0, $crcc->notifications()->where('type', NextDaySchools::class)->count());

        Cache::forget(RunDueAlerts::LOCK);
        $this->travelTo(today()->setTime(18, 5));
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame(1, $crcc->notifications()->where('type', NextDaySchools::class)->count(), 'sent by the page view');

        $this->get(route('dashboard'))->assertOk(); // within 5 minutes: not run again
        $this->assertSame(1, $crcc->notifications()->where('type', NextDaySchools::class)->count());
    }

    public function test_no_page_view_run_while_the_scheduler_is_running(): void
    {
        config(['inspection.daily_list.alert_fallback' => true]);
        $crcc = $this->officer(UserLevel::Cluster, Cluster::orderBy('id')->firstOrFail());
        $this->travelTo(today()->setTime(18, 5));
        SchedulerHeartbeat::beat();
        $this->actingAs($crcc)->get(route('today.index'))->assertOk();
        $this->assertSame(0, $crcc->notifications()->count());
        $this->assertFalse(Cache::has(RunDueAlerts::LOCK));
    }
}
