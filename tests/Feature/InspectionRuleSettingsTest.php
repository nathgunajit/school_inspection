<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Inspection;
use App\Models\InspectionStartSetting;
use App\Models\School;
use App\Models\User;
use App\Services\InspectionStartGuard;
use App\Services\ScheduleService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 26-11-2026: Admin Settings → Inspection Rules. The State Admin chooses how long a same-level submission blocks a
 * school (month / week / day / none; an open same-level draft always blocks) and whether officers of different levels
 * may inspect the same school on the same day.
 */
class InspectionRuleSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00'); // a Friday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rules(string $window, bool $cross = true): void
    {
        InspectionStartSetting::current()->update(['repeat_window' => $window, 'cross_level' => $cross]);
    }

    /** A submitted inspection of $school by $officer on $date. */
    private function submitted(User $officer, School $school, string $date): Inspection
    {
        $i = $this->startInspection($officer, $school);
        $i->forceFill(['status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_date' => $date, 'inspection_no' => 'T/'.$i->id])->save();

        return $i;
    }

    /** [two Cluster officers of one cluster, a school there] */
    private function setupCluster(): array
    {
        $a = $this->officer(UserLevel::Cluster);
        $cluster = $a->currentJurisdiction->cluster;

        return [$a, $this->officer(UserLevel::Cluster, $cluster), $this->schoolIn($cluster), $cluster];
    }

    private function blockType(User $officer, School $school): ?string
    {
        return app(InspectionStartGuard::class)->check($officer, $school)['type'] ?? null;
    }

    public function test_default_is_the_calendar_month_and_different_levels_allowed(): void
    {
        $s = InspectionStartSetting::current();
        $this->assertSame(['month', true], [$s->repeat_window, $s->cross_level]);

        [$a, $b, $school] = $this->setupCluster();
        $this->submitted($a, $school, '2026-11-03');
        $this->assertSame(InspectionStartGuard::OTHER_MONTH, $this->blockType($b, $school));
        $this->assertSame('2026-12-01', InspectionStartGuard::reopensOn()->toDateString());
    }

    public function test_week_day_and_none_windows(): void
    {
        [$a, $b, $school, $cluster] = $this->setupCluster();
        $old = $this->submitted($a, $school, '2026-11-16'); // Monday of this week

        $this->rules('week');
        $this->assertSame(InspectionStartGuard::OTHER_MONTH, $this->blockType($b, $school));
        $this->assertSame('2026-11-23', InspectionStartGuard::reopensOn()->toDateString(), 'next Monday');
        $old->forceFill(['inspection_date' => '2026-11-13'])->save(); // last week
        $this->assertNull($this->blockType($b, $school));

        $this->rules('day');
        $this->assertNull($this->blockType($b, $school), 'last week does not block');
        $old->forceFill(['inspection_date' => '2026-11-19'])->save(); // yesterday
        $this->assertNull($this->blockType($b, $school));
        $old->forceFill(['inspection_date' => '2026-11-20'])->save(); // today
        $this->assertSame(InspectionStartGuard::OTHER_MONTH, $this->blockType($b, $school));
        $this->actingAs($b)->get(route('schools.show', $school))->assertOk()->assertSee('Already inspected today')->assertSee('21 Nov 2026');

        // none: a submission never blocks, an open same-level draft still does
        $this->rules('none');
        $this->assertNull($this->blockType($b, $school));
        $other = School::where('cluster_id', $cluster->id)->where('id', '!=', $school->id)->orderBy('id')->firstOrFail();
        $this->startInspection($a, $other);
        $this->assertSame(InspectionStartGuard::OTHER_DRAFT, $this->blockType($b, $other));
    }

    public function test_different_levels_blocked_only_on_the_same_day_when_switched_off(): void
    {
        [$a, , $school, $cluster] = $this->setupCluster();
        $block = $this->officer(UserLevel::Block, $cluster);
        $draft = $this->startInspection($a, $school); // the CRCC's draft today

        $this->assertNull($this->blockType($block, $school), 'allowed by default');

        $this->rules('month', false);
        $this->assertSame(InspectionStartGuard::OTHER_LEVEL_TODAY, $this->blockType($block, $school));
        $this->actingAs($block)->post(route('inspections.start', $school))->assertRedirect(route('schools.show', $school))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'Officers of different levels cannot inspect the same school on the same day'));
        $this->get(route('schools.show', $school))->assertSee('Inspected today by an officer of another level');
        $this->get(route('schools.index', ['q' => $school->udise_code]), ['X-Requested-With' => 'XMLHttpRequest'])->assertSee('Inspected today by another level');

        // the planner / Today's Schools: blocked on that day only
        $plan = app(ScheduleService::class);
        $this->assertArrayHasKey($school->id, $plan->blockedSchools($block, today(), collect([$school->id])));
        $this->assertArrayNotHasKey($school->id, $plan->blockedSchools($block, today()->addDay(), collect([$school->id])));

        // yesterday's inspection by another level does not block; a cancelled one never does
        $draft->forceFill(['inspection_date' => '2026-11-19'])->save();
        $this->assertNull($this->blockType($block, $school));
        $draft->forceFill(['inspection_date' => '2026-11-20', 'status' => InspectionStatus::Cancelled])->save();
        $this->assertNull($this->blockType($block, $school));
    }

    public function test_plan_blocking_follows_the_window(): void
    {
        [$a, $b, $school] = $this->setupCluster();
        $this->submitted($a, $school, '2026-11-17');
        $plan = app(ScheduleService::class);
        $on = fn (string $d) => array_key_exists($school->id, $plan->blockedSchools($b, Carbon::parse($d), collect([$school->id])));

        $this->assertTrue($on('2026-11-28'), 'month: same month');
        $this->rules('week');
        $this->assertTrue($on('2026-11-22'));
        $this->assertFalse($on('2026-11-24'), 'next week');
        $this->rules('none');
        $this->assertFalse($on('2026-11-17'));
    }

    /** 26-11-2026: Today's Schools chooses its own period for "Skip schools taken by another officer". */
    public function test_todays_schools_skip_period_overrides_the_rules(): void
    {
        [$a, $b, $school] = $this->setupCluster();
        $this->submitted($a, $school, '2026-11-23'); // a Monday
        $plan = app(ScheduleService::class);
        $on = fn (string $d, ?string $p) => array_key_exists($school->id, $plan->blockedSchools($b, Carbon::parse($d), collect([$school->id]), $p));

        $this->rules('none'); // the Inspection Rules do not decide the list's period
        $this->assertFalse($on('2026-11-26', null));
        $this->assertTrue($on('2026-11-30', 'month'));
        $this->assertTrue($on('2026-11-26', 'week'));
        $this->assertFalse($on('2026-11-30', 'week'), 'next week');
        $this->assertFalse($on('2026-11-26', 'day'));
        $this->assertTrue($on('2026-11-23', 'day'));

        // a draft (being inspected now) is skipped whatever the period
        $other = School::where('cluster_id', $school->cluster_id)->where('id', '!=', $school->id)->orderBy('id')->firstOrFail();
        $this->startInspection($a, $other);
        $this->assertArrayHasKey($other->id, $plan->blockedSchools($b, today(), collect([$other->id]), 'day'));

        // saved from the settings page, part of the list stamp; a wrong value is refused
        $payload = ['scope' => 'state', 'enabled' => 1, 'levels' => ['cluster'], 'daily_limit' => 2, 'selection' => 'priority', 'low_rating_below' => 50,
            'recent_days' => 30, 'skip_blocked' => 1];
        $stamp = \App\Models\DailyDisplaySetting::state()->listStamp();
        $this->actingAs($this->admin())->put(route('today.settings.update'), $payload + ['skip_window' => 'week'])->assertSessionHasNoErrors();
        $s = \App\Models\DailyDisplaySetting::state()->fresh();
        $this->assertSame('week', $s->skip_window);
        $this->assertNotSame($stamp, $s->listStamp());
        $this->put(route('today.settings.update'), $payload + ['skip_window' => 'year'])->assertSessionHasErrors('skip_window');
        $this->get(route('today.settings'))->assertOk()->assertSee('id="sw0week" checked', false);
    }

    public function test_only_the_state_admin_manages_the_rules(): void
    {
        $officer = $this->officer(UserLevel::District);
        $this->actingAs($officer)->get(route('admin.inspection-rules'))->assertForbidden();
        $this->actingAs($officer)->put(route('admin.inspection-rules.update'), ['repeat_window' => 'day'])->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.inspection-rules'))->assertOk()
            ->assertSee('When can a school be inspected again?')->assertSee('This week (Monday to Sunday)')->assertSee('even on the same day');
        $this->put(route('admin.inspection-rules.update'), ['repeat_window' => 'weekly'])->assertSessionHasErrors('repeat_window');
        $this->put(route('admin.inspection-rules.update'), ['repeat_window' => 'week'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'from the next Monday') && str_contains($m, 'may not inspect the same school on the same day'));

        $s = InspectionStartSetting::query()->first();
        $this->assertSame(['week', false, $admin->id], [$s->repeat_window, $s->cross_level, $s->updated_by]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inspection_rules_updated', 'user_id' => $admin->id]);
        $this->get(route('dashboard'))->assertSee(route('admin.inspection-rules'), false);
    }
}
