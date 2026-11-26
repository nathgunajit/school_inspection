<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\DailyDisplaySetting;
use App\Models\DailySchoolList;
use App\Models\Holiday;
use App\Models\School;
use App\Notifications\NextDaySchools;
use App\Notifications\PendingSchoolsReminder;
use App\Notifications\TodayListChanged;
use App\Services\DailySchoolListService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Modification V15 (26-11-2026): Today's Schools skip switch, carry of skipped schools, alerts and the list lock. */
class TodaySchoolsV15Test extends TestCase
{
    private Cluster $cluster;

    private ?\App\Models\User $crccUser = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cluster = Cluster::orderBy('id')->firstOrFail();
        $base = School::where('cluster_id', $this->cluster->id)->orderBy('id')->firstOrFail();
        foreach ([1, 2, 3] as $n) {
            $base->replicate()->fill(['udise_code' => '1899999998'.$n, 'name' => 'Extra School '.$n])->save();
        }
        DailyDisplaySetting::state()->update(['weekly_off' => []]); // never depend on the weekday
    }

    private function crcc()
    {
        return $this->crccUser ??= $this->officer(UserLevel::Cluster, $this->cluster);
    }

    private function rows($officer, $day)
    {
        return DailySchoolList::where('officer_id', $officer->id)->where('list_date', $day)->orderBy('position')->get();
    }

    private function today(): string
    {
        return today()->toDateString();
    }

    public function test_skip_can_be_switched_off_for_everyone(): void
    {
        $crcc = $this->crcc();
        $this->actingAs($crcc)->get(route('today.index'))->assertOk()->assertSee('aria-label="Not visited"', false);
        $row = $this->rows($crcc, $this->today())->first();

        DailyDisplaySetting::state()->update(['allow_skip' => false]);
        $this->get(route('today.index'))->assertOk()->assertDontSee('aria-label="Not visited"', false);
        $this->post(route('today.skip', $row), ['reason' => 'Road flooded'])->assertSessionHasErrors('reason');
        $this->assertSame('pending', $row->fresh()->status);

        // a district setting cannot switch it back on: the value always comes from the State
        $districtId = $this->cluster->block->district_id;
        DailyDisplaySetting::create(['district_id' => $districtId, 'allow_skip' => true] + DailyDisplaySetting::defaults());
        $this->assertFalse(DailyDisplaySetting::forDistrict($districtId)->allow_skip);
    }

    public function test_state_saves_skip_and_alert_times(): void
    {
        $admin = $this->admin();
        $payload = ['scope' => 'state', 'enabled' => 1, 'levels' => ['cluster'], 'daily_limit' => 2, 'selection' => 'priority', 'low_rating_below' => 50,
            'recent_days' => 30, 'carry_forward' => 1, '_alerts' => 1, 'notify_enabled' => 1, 'notify_time' => '19:30', 'reminder_time' => '15:00'];
        $this->actingAs($admin)->get(route('today.settings'))->assertOk()->assertSee('Next-day list alert');
        $this->put(route('today.settings.update'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $s = DailyDisplaySetting::state()->fresh();
        $this->assertSame([false, true, '19:30', false, '15:00'], [$s->allow_skip, $s->notify_enabled, $s->notify_time, $s->reminder_enabled, $s->reminder_time]);

        $this->put(route('today.settings.update'), ['notify_time' => '7 pm'] + $payload)->assertSessionHasErrors('notify_time');
    }

    public function test_a_skipped_school_moves_to_the_next_working_day_first(): void
    {
        $crcc = $this->crcc();
        $list = app(DailySchoolListService::class);
        $yesterday = today()->subDay()->toDateString();
        $s = School::where('cluster_id', $this->cluster->id)->orderBy('id')->first();
        DailySchoolList::create(['officer_id' => $crcc->id, 'list_date' => $yesterday, 'school_id' => $s->id, 'position' => 1, 'reason' => 'not_inspected',
            'source' => 'auto', 'status' => 'skipped', 'skip_reason' => 'Road flooded']);

        $this->actingAs($crcc)->get(route('today.index'))->assertOk()->assertSee('Skipped earlier')->assertSee('Priority');
        $rows = $this->rows($crcc, $this->today());
        $this->assertSame($s->id, $rows->first()->school_id, 'carried schools are listed first');
        $this->assertSame(['carried', 'carried_skipped'], [$rows->first()->source, $rows->first()->reason]);
        $this->assertCount(3, $rows, 'the carried school is extra to the daily limit of 2');
        $old = DailySchoolList::where('list_date', $yesterday)->first();
        $this->assertSame(['skipped', $this->today(), 'Road flooded'], [$old->status, $old->carried_to, $old->skip_reason]);

        // carried only once
        $list->generate($crcc, today(), $list->settingFor($crcc));
        $this->assertCount(3, $this->rows($crcc, $this->today()));
    }

    public function test_notice_prepares_the_next_working_day_once_and_skips_holidays(): void
    {
        $crcc = $this->crcc();
        $tomorrow = today()->addDay()->toDateString();
        $after = today()->addDays(2)->toDateString();
        Holiday::create(['date' => $tomorrow, 'name' => 'Local holiday', 'district_id' => $this->cluster->block->district_id]);
        $this->actingAs($crcc)->get(route('today.index'))->assertOk();

        $this->artisan('today:notify', ['--now' => today()->format('Y-m-d').' 12:00'])->assertSuccessful()->expectsOutputToContain('Nothing to send now');
        $this->assertSame(0, $crcc->notifications()->count());

        $this->artisan('today:notify', ['--now' => today()->format('Y-m-d').' 18:05'])->expectsOutputToContain('Next-day notices: 1')->assertSuccessful();
        $this->assertSame(0, $this->rows($crcc, $tomorrow)->count(), 'no list on the holiday');
        $this->assertSame(4, $this->rows($crcc, $after)->count(), 'the next working day after it: 2 carried + the limit of 2');
        $this->assertSame(1, $crcc->notifications()->where('type', NextDaySchools::class)->count());
        $today = $this->rows($crcc, $this->today());
        $this->assertTrue($today->every(fn ($r) => $r->status === 'pending'), "today's rows are still the officer's to act on");
        $this->assertCount(2, $this->rows($crcc, $after)->where('source', 'carried'), "today's pending schools are copied ahead, first");

        $this->artisan('today:notify', ['--now' => today()->format('Y-m-d').' 18:10', '--force' => true])->assertSuccessful();
        $this->assertSame(1, $crcc->notifications()->where('type', NextDaySchools::class)->count(), 'sent once');

        // the bell
        $this->actingAs($crcc)->getJson(route('alerts.count', ['items' => 1]))->assertOk()->assertJsonPath('count', 2) // the forced run also sent the 14:00 reminder
            ->assertJsonPath('items.0.unread', true);
        $alert = $crcc->notifications()->where('type', NextDaySchools::class)->first();
        $this->get(route('alerts.open', $alert->id))->assertRedirect();
        $this->assertNotNull($alert->fresh()->read_at);
        $this->getJson(route('alerts.count'))->assertJsonPath('count', 1);
        $this->get(route('alerts.index'))->assertOk()->assertSee('to visit');
        $this->actingAs($this->officer(UserLevel::Cluster, $this->cluster))->get(route('alerts.open', $alert->id))->assertNotFound();

        // the list made ahead can be opened, not acted on
        $this->actingAs($crcc)->get(route('today.index', ['date' => $after]))->assertOk()->assertSee('made ahead')->assertDontSee('aria-label="Not visited"', false);
    }

    public function test_after_the_notice_a_visit_or_skip_today_updates_the_next_list(): void
    {
        $crcc = $this->crcc();
        $tomorrow = today()->addDay();
        $this->actingAs($crcc)->get(route('today.index'))->assertOk();
        [$a, $b] = $this->rows($crcc, $this->today())->all();
        $this->artisan('today:notify', ['--now' => today()->format('Y-m-d').' 18:05'])->assertSuccessful();
        $this->assertCount(2, $this->rows($crcc, $tomorrow->toDateString())->where('source', 'carried'));

        // visited this evening: off tomorrow's list
        $i = $this->startInspection($crcc, School::findOrFail($a->school_id));
        app(DailySchoolListService::class)->markVisited($i);
        $this->assertFalse($this->rows($crcc, $tomorrow->toDateString())->contains('school_id', $a->school_id));
        // skipped this evening
        $this->actingAs($crcc)->post(route('today.skip', $b), ['reason' => 'Headmaster away'])->assertRedirect();

        // the next morning the list is brought up to date
        $this->travelTo($tomorrow->copy()->setTime(9, 0));
        $this->get(route('today.index'))->assertOk();
        $rows = $this->rows($crcc, today()->toDateString());
        $this->assertSame('carried_skipped', $rows->firstWhere('school_id', $b->school_id)->reason);
        $this->assertSame(today()->toDateString(), $b->fresh()->carried_to);
        $this->assertGreaterThanOrEqual(3, $rows->count(), 'the visited one is replaced by a new pick');
    }

    public function test_reminder_only_for_officers_with_pending_schools(): void
    {
        $crcc = $this->crcc();
        $other = $this->officer(UserLevel::Cluster, Cluster::where('id', '!=', $this->cluster->id)->where('block_id', $this->cluster->block_id)->first() ?? $this->cluster);
        $this->actingAs($crcc)->get(route('today.index'))->assertOk();
        DailySchoolList::where('officer_id', $other->id)->delete();
        $this->artisan('today:notify', ['--now' => today()->format('Y-m-d').' 14:05'])->assertSuccessful();
        $this->assertSame(1, $crcc->notifications()->where('type', PendingSchoolsReminder::class)->count());
        DailySchoolList::where('officer_id', $crcc->id)->update(['status' => 'visited']);
        $this->artisan('today:notify', ['--now' => today()->format('Y-m-d').' 14:30', '--force' => true])->assertSuccessful();
        $this->assertSame(1, $crcc->notifications()->where('type', PendingSchoolsReminder::class)->count());
    }

    public function test_starting_an_inspection_locks_the_list_until_the_state_admin_allows_changes(): void
    {
        $crcc = $this->crcc();
        $block = $this->officer(UserLevel::Block, $this->cluster);
        $this->actingAs($crcc)->get(route('today.index'))->assertOk();
        [$a, $b] = $this->rows($crcc, $this->today())->all();
        $free = School::where('cluster_id', $this->cluster->id)->whereNotIn('id', [$a->school_id, $b->school_id])->orderBy('id')->firstOrFail();

        // before any start: replace works, and the officer is told
        $this->actingAs($block)->post(route('today.replace', $b), ['school_id' => $free->id])->assertRedirect()->assertSessionHasNoErrors();
        $b = DailySchoolList::where('officer_id', $crcc->id)->where('school_id', $free->id)->firstOrFail();
        $this->assertSame(['manual', $block->id], [$b->source, $b->assigned_by]);
        $this->assertTrue(AuditLog::where('action', 'daily_list_replaced')->exists());
        $this->assertSame(1, $crcc->notifications()->where('type', TodayListChanged::class)->count());

        // a started school can be neither removed nor replaced
        $this->startInspection($crcc, School::findOrFail($a->school_id));
        $service = app(DailySchoolListService::class);
        $this->assertTrue($service->isLocked($crcc, $this->today()));
        $this->actingAs($block)->delete(route('today.remove', $b))->assertSessionHasErrors('locked');
        $this->assertNotNull($b->fresh());
        $spare = School::where('cluster_id', $this->cluster->id)->whereNotIn('id', DailySchoolList::pluck('school_id'))->orderBy('id')->firstOrFail();
        $this->post(route('today.assign.store'), ['officer_id' => $crcc->id, 'date' => $this->today(), 'school_ids' => [$spare->id]])->assertSessionHasErrors('locked');
        $this->get(route('today.assign', ['officer' => $crcc->id]))->assertOk()->assertSee('list is locked')->assertDontSee('Allow changes');
        $this->post(route('today.unlock'), ['officer_id' => $crcc->id, 'date' => $this->today(), 'unlock_reason' => 'Officer on leave'])->assertForbidden();

        // the State Administrator allows changes, with a reason
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('today.assign', ['officer' => $crcc->id]))->assertOk()->assertSee('Allow changes');
        $this->post(route('today.unlock'), ['officer_id' => $crcc->id, 'date' => $this->today(), 'unlock_reason' => ''])->assertSessionHasErrors('unlock_reason');
        $this->post(route('today.unlock'), ['officer_id' => $crcc->id, 'date' => $this->today(), 'unlock_reason' => 'Officer called to a meeting'])->assertRedirect();
        $this->assertFalse($service->isLocked($crcc, $this->today()));
        $this->assertTrue(AuditLog::where('action', 'daily_list_unlocked')->exists());

        $this->actingAs($block)->delete(route('today.remove', $b))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($b->fresh());
        // the started school itself still stays
        $draftRow = DailySchoolList::where('officer_id', $crcc->id)->where('school_id', $a->school_id)->first();
        $this->delete(route('today.remove', $draftRow))->assertSessionHasErrors('locked');

        // the next start locks it again
        $this->startInspection($crcc, $spare);
        $this->assertTrue($service->isLocked($crcc, $this->today()));
    }

    public function test_settings_change_refreshes_lists_at_once(): void
    {
        $crcc = $this->crcc();
        $this->actingAs($crcc)->get(route('today.index'))->assertOk();
        $this->assertCount(2, $this->rows($crcc, $this->today()));

        $payload = ['scope' => 'state', 'enabled' => 1, 'levels' => ['cluster'], 'daily_limit' => 4, 'selection' => 'priority', 'low_rating_below' => 50,
            'recent_days' => 30, 'carry_forward' => 1];
        $this->actingAs($this->admin())->put(route('today.settings.update'), $payload)->assertRedirect();
        $this->assertCount(4, $this->rows($crcc, $this->today()), 'changed without the officer opening the list');
    }
}
