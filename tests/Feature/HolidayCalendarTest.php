<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Exports\ArrayExport;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\Holiday;
use App\Models\Permission;
use App\Models\School;
use App\Services\HolidayCalendar;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** V14 item 3 (25-11-2026): holiday calendar with state and district (local) holidays. */
class HolidayCalendarTest extends TestCase
{
    private function districtAdmin(Cluster $cluster)
    {
        $u = $this->officer(UserLevel::District, $cluster);
        $this->grant($u, Permission::MANAGE_USERS);

        return $u->fresh();
    }

    public function test_state_admin_adds_state_and_district_holidays_and_officers_see_their_own(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $other = $this->otherCluster($cluster);
        $admin = $this->admin();
        $date = today()->addDays(3)->toDateString();

        $this->actingAs($admin)->post(route('holidays.store'), ['date' => $date, 'name' => 'Bohag Bihu'])->assertRedirect();
        $this->post(route('holidays.store'), ['date' => $date, 'name' => 'Local Mela', 'district_id' => $other->block->district_id])->assertRedirect();
        $this->post(route('holidays.store'), ['date' => $date, 'name' => 'Bohag Bihu'])->assertSessionHasErrors('name'); // duplicate
        $this->assertSame(2, Holiday::count());
        $this->assertTrue(AuditLog::where('action', 'holiday_created')->exists());

        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $this->actingAs($officer)->get(route('holidays.index', ['month' => substr($date, 0, 7)]))->assertOk()
            ->assertSee('Bohag Bihu')->assertDontSee('Local Mela')->assertDontSee('Add holiday');
        $this->post(route('holidays.store'), ['date' => $date, 'name' => 'X'])->assertForbidden();

        $calendar = app(HolidayCalendar::class);
        $this->assertTrue($calendar->isHoliday($date, $cluster->block->district_id));
        $this->assertSame('Bohag Bihu, Local Mela', $calendar->holiday($date, $other->block->district_id));
        $this->assertFalse($calendar->isWorkingDay($date, null));
    }

    public function test_district_officer_manages_only_own_district_holidays(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $other = $this->otherCluster($cluster);
        $own = $cluster->block->district_id;
        $u = $this->districtAdmin($cluster);
        $date = today()->addDays(5)->toDateString();

        $this->actingAs($u)->post(route('holidays.store'), ['date' => $date, 'name' => 'State try'])->assertSessionHasErrors('district_id');
        $this->post(route('holidays.store'), ['date' => $date, 'name' => 'Other try', 'district_id' => $other->block->district_id])->assertSessionHasErrors('district_id');
        $this->post(route('holidays.store'), ['date' => $date, 'name' => 'Raas Mahotsav', 'district_id' => $own])->assertRedirect();
        $h = Holiday::firstOrFail();

        $state = Holiday::create(['date' => $date, 'name' => 'Republic', 'district_id' => null]);
        $this->put(route('holidays.update', $state), ['date' => $date, 'name' => 'Changed'])->assertForbidden();
        $this->delete(route('holidays.destroy', $state))->assertForbidden();

        $this->put(route('holidays.update', $h), ['date' => $date, 'name' => 'Raas Utsav', 'district_id' => $own])->assertRedirect();
        $this->assertSame('Raas Utsav', $h->fresh()->name);
        $this->delete(route('holidays.destroy', $h))->assertRedirect();
        $this->assertNull(Holiday::find($h->id));
        $this->assertTrue(AuditLog::where('action', 'holiday_deleted')->exists());

        // a District officer without Manage users cannot add
        $plain = $this->officer(UserLevel::District, $cluster);
        $this->actingAs($plain)->post(route('holidays.store'), ['date' => $date, 'name' => 'Nope', 'district_id' => $own])->assertForbidden();
    }

    public function test_import_checks_every_row_before_saving(): void
    {
        $admin = $this->admin();
        $code = Cluster::orderBy('id')->first()->block->district->code;
        $y = now()->year + 1;
        $file = fn (array $rows) => UploadedFile::fake()->createWithContent('h.xlsx', Excel::raw(new ArrayExport(['date', 'name', 'district_code'], $rows), \Maatwebsite\Excel\Excel::XLSX));

        $this->actingAs($admin)->post(route('holidays.import'), ['file' => $file([["$y-01-14", 'Magh Bihu', ''], ['nonsense', 'Bad', ''], ["$y-02-01", 'X', '99999']])])
            ->assertSessionHas('import_errors');
        $this->assertSame(0, Holiday::count());

        $this->post(route('holidays.import'), ['file' => $file([["$y-01-14", 'Magh Bihu', ''], ["14-04-$y", 'Bohag Bihu', $code]])])->assertSessionHas('success');
        $this->assertSame(2, Holiday::count());
        $this->assertSame("$y-04-14", Holiday::where('name', 'Bohag Bihu')->value('date'));
        $this->post(route('holidays.import'), ['file' => $file([["$y-01-14", 'Magh Bihu', '']])])->assertSessionHas('success');
        $this->assertSame(2, Holiday::count()); // already there: skipped
        $this->assertSame(2, AuditLog::where('action', 'holidays_imported')->count());
        $this->get(route('holidays.template'))->assertOk();
    }

    public function test_no_plan_on_a_holiday(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $date = today()->addDays(2)->toDateString();
        Holiday::create(['date' => $date, 'name' => 'Me-Dam-Me-Phi', 'district_id' => $cluster->block->district_id]);
        $ids = School::where('cluster_id', $cluster->id)->orderBy('id')->take(1)->pluck('id')->all();

        $this->actingAs($officer)->postJson(route('schedules.store'), ['officer_id' => $officer->id, 'date' => $date, 'school_ids' => $ids, 'action' => 'save'])
            ->assertStatus(422)->assertJsonValidationErrors('date');
        $this->get(route('schedules.index', ['view' => 'week', 'date' => $date]))->assertOk()->assertSee('Me-Dam-Me-Phi');

        // another district's local holiday does not block
        $other = $this->otherCluster($cluster);
        $far = $this->officer(UserLevel::Cluster, $other);
        $this->actingAs($far)->postJson(route('schedules.store'), ['officer_id' => $far->id, 'date' => $date,
            'school_ids' => School::where('cluster_id', $other->id)->orderBy('id')->take(1)->pluck('id')->all(), 'action' => 'save'])->assertOk();
    }

    /** 25-11-2026: restricted and half holidays are shown, but never block a plan or the daily list. */
    public function test_restricted_and_half_holidays_are_shown_only(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $district = $cluster->block->district_id;
        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $date = today()->addDays(2)->toDateString();
        Holiday::create(['date' => $date, 'name' => 'Uruka of Magh Bihu', 'type' => 'restricted']);
        Holiday::create(['date' => $date, 'name' => 'Lakshmi Puja', 'type' => 'half']);

        $calendar = app(HolidayCalendar::class);
        $this->assertNull($calendar->holiday($date, $district));
        $this->assertSame(2, $calendar->between($date, $date, $district)->count());
        $this->assertSame(['Lakshmi Puja', 'Uruka of Magh Bihu'], $calendar->upcoming($district)->pluck('name')->all());

        \App\Models\DailyDisplaySetting::state()->update(['weekly_off' => []]);
        $ids = School::where('cluster_id', $cluster->id)->orderBy('id')->take(1)->pluck('id')->all();
        $this->actingAs($officer)->postJson(route('schedules.store'), ['officer_id' => $officer->id, 'date' => $date, 'school_ids' => $ids, 'action' => 'save'])->assertOk();

        $this->get(route('holidays.index', ['month' => substr($date, 0, 7)]))->assertOk()->assertSee('Uruka of Magh Bihu')->assertSee('is-restricted', false);
        $this->get(route('holidays.index', ['month' => substr($date, 0, 7), 'restricted' => '0']))->assertOk()->assertDontSee('Uruka of Magh Bihu')->assertSee('Lakshmi Puja');

        // the dashboard shows the calendar and the upcoming holidays
        $this->get(route('dashboard'))->assertOk()->assertSee('Upcoming holidays')->assertSee('Uruka of Magh Bihu')->assertSee('Restricted');

        // the admin sets the type
        $this->actingAs($this->admin())->post(route('holidays.store'), ['date' => $date, 'name' => 'Deuri Bihu', 'type' => 'restricted'])->assertRedirect();
        $this->assertSame('restricted', Holiday::where('name', 'Deuri Bihu')->value('type'));
        $this->post(route('holidays.store'), ['date' => $date, 'name' => 'Odd', 'type' => 'weekly'])->assertSessionHasErrors('type');
        $this->get(route('dashboard'))->assertOk()->assertSee('Notices &amp; orders sent', false);
    }

    /** 25-11-2026: Notices & Orders sits after Map View in the menu; the dashboard has the calendar and notices rail. */
    public function test_sidebar_lists_notices_after_map_view(): void
    {
        $u = $this->officer(UserLevel::District, Cluster::orderBy('id')->first());
        $this->actingAs($u)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Schools', 'Inspections', 'Reports', 'Map View', 'Notices &amp; Orders', 'Inspection Schedules'], false)
            ->assertSee('Upcoming holidays')->assertSee('Notices &amp; orders', false)->assertSee('dash-hero-pill', false);

        // 25-11-2026: merged items switch through tabs; Users / Masters & Admin fold away
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('nav-group-toggle', false)
            ->assertSeeInOrder(['data-bs-target="#nav-users"', 'data-bs-target="#nav-masters"', 'data-bs-target="#nav-settings"'], false)
            ->assertSeeInOrder(['Users', 'Masters', 'Admin Settings'])->assertDontSee('Masters &amp; Admin', false)
            ->assertDontSee('collapse nav-group show', false);
        $this->get(route('admin.audit.index'))->assertOk()->assertSee('collapse nav-group show" id="nav-settings"', false);
        $this->get(route('admin.locations.index'))->assertOk()->assertSee('collapse nav-group show" id="nav-masters"', false);
        $settings = $this->get(route('today.settings'))->assertOk()->assertSee('collapse nav-group show" id="nav-settings"', false)->getContent();
        $this->assertDoesNotMatchRegularExpression('~href="'.preg_quote(route('today.index'), '~').'" class="nav-link active"~', $settings, 'Today\'s Schools is not highlighted on its settings page');
        $this->get(route('reports.coverage'))->assertOk()->assertSee('page-tabs', false)
            ->assertSeeInOrder([route('reports.coverage'), route('reports.ratings'), route('reports.network')], false);

        $crcc = $this->officer(UserLevel::Cluster, Cluster::orderBy('id')->first());
        $this->actingAs($crcc)->get(route('schools.index'))->assertOk()->assertSee('page-tabs', false)->assertSee(route('schools.mine'), false);
        $this->get(route('inspections.mine'))->assertOk()->assertSeeInOrder(['page-tabs', 'My Inspections', 'In My Area'], false);
        $this->get(route('reports.coverage'))->assertOk()->assertDontSee(route('reports.ratings'), false); // no Rating tab for officers

        // a District officer with Manage users: Admin Settings with their own settings pages, no Masters
        $dpo = $this->officer(UserLevel::District, Cluster::orderBy('id')->first());
        $this->grant($dpo, 'manage_users');
        $this->actingAs($dpo)->get(route('dashboard'))->assertOk()->assertSee('id="nav-settings"', false)
            ->assertSee(route('reports.coverage.settings'), false)->assertSee(route('today.settings'), false)
            ->assertDontSee('id="nav-masters"', false)->assertDontSee(route('admin.audit.index'), false);
    }

    public function test_import_reads_the_type_column_and_templates_download(): void
    {
        $y = now()->year + 1;
        $file = fn (array $rows) => UploadedFile::fake()->createWithContent('h.xlsx',
            Excel::raw(new ArrayExport(['date', 'name', 'type', 'district_code'], $rows), \Maatwebsite\Excel\Excel::XLSX));
        $this->actingAs($this->admin())->post(route('holidays.import'), ['file' => $file([["$y-01-13", 'Uruka', 'weekly', '']])])->assertSessionHas('import_errors');
        $this->post(route('holidays.import'), ['file' => $file([["$y-01-13", 'Uruka', 'R.H.', ''], ["$y-10-25", 'Lakshmi Puja', 'Half holiday', ''], ["$y-01-14", 'Magh Bihu', '', '']])])
            ->assertSessionHas('success');
        $this->assertSame(['Lakshmi Puja' => 'half', 'Magh Bihu' => 'gazetted', 'Uruka' => 'restricted'], Holiday::orderBy('name')->pluck('type', 'name')->all());

        $this->get(route('holidays.template'))->assertOk()->assertDownload('holiday-template.xlsx');
        $this->get(route('holidays.template', ['fill' => 'assam-2026']))->assertOk()->assertDownload('holiday-list-assam-2026.xlsx');
    }

    public function test_assam_2026_seeder_loads_the_notification_once(): void
    {
        $r = (new \Database\Seeders\AssamHolidaySeeder)->run();
        // the demo master has none of the 11 Ali-Aye-Ligang districts
        $this->assertSame(['gazetted' => 35, 'half' => 1, 'restricted' => 39], Holiday::selectRaw('type, count(*) c')->groupBy('type')->orderBy('type')->pluck('c', 'type')->map(fn ($c) => (int) $c)->all());
        $this->assertCount(11, $r['missing']);
        $this->assertSame(0, (new \Database\Seeders\AssamHolidaySeeder)->run()['added']);

        $calendar = app(HolidayCalendar::class);
        $this->assertSame('Holiday: Bohag Bihu', $calendar->offReason('2026-04-15', null, []));   // Deuri Bihu (restricted) not shown as off
        $this->assertTrue($calendar->isWorkingDay('2026-02-18', null, []));                        // Ali Aye Ligang restricted outside the 11 districts

        // a district of the 11 gets the full holiday
        $d = \App\Models\District::orderBy('id')->first();
        $d->update(['code' => '1812']);
        (new \Database\Seeders\AssamHolidaySeeder)->run();
        $this->assertFalse($calendar->isWorkingDay('2026-02-18', $d->id, []));
    }

    /** 25-11-2026: the data-holiday-day cells of a page, by date. */
    private function dayCells(string $html): array
    {
        preg_match_all('~data-holiday-day="([^"]*)"~', $html, $m);

        return collect($m[1])->map(fn ($j) => json_decode(html_entity_decode($j, ENT_QUOTES), true))->keyBy('date')->all();
    }

    /** 25-11-2026: click a date: its holidays in a popup; the State Admin adds / removes there (Holiday Calendar and dashboard). */
    public function test_calendar_day_popup_shows_holidays_and_lets_managers_add_and_remove(): void
    {
        $day = today()->startOfMonth()->addDays(9);
        $empty = today()->startOfMonth()->addDays(11);
        $h = Holiday::create(['date' => $day->toDateString(), 'name' => 'Test Bihu', 'district_id' => null]);
        $admin = $this->admin();

        $page = $this->actingAs($admin)->get(route('holidays.index'))->assertOk()->assertSee('id="holiday-day"', false)
            ->assertSee('Add a holiday on this date')->assertSee('Whole state (state holiday)')->getContent();
        $cells = $this->dayCells($page);
        $this->assertSame('Test Bihu', $cells[$day->toDateString()]['holidays'][0]['name']);
        $this->assertSame(route('holidays.destroy', $h), $cells[$day->toDateString()]['holidays'][0]['remove']);
        $this->assertArrayHasKey($empty->toDateString(), $cells, 'an empty day is clickable for a manager');

        // dashboard: the same popup; adding there returns to the dashboard
        $dash = $this->get(route('dashboard'))->assertOk()->assertSee('id="holiday-day"', false)->getContent();
        $this->assertSame('Test Bihu', $this->dayCells($dash)[$day->toDateString()]['holidays'][0]['name']);
        $this->post(route('holidays.store'), ['_day' => 1, 'back' => 'dashboard', 'date' => $empty->toDateString(), 'name' => 'Dashboard Day', 'type' => 'gazetted'])
            ->assertRedirect(route('dashboard'));
        $this->assertTrue(Holiday::where('name', 'Dashboard Day')->exists());
        $this->delete($cells[$day->toDateString()]['holidays'][0]['remove'])->assertRedirect();
        $this->assertNull(Holiday::find($h->id));

        // an officer only views: holiday days only, no remove, no add form
        $crcc = $this->officer(UserLevel::Cluster, Cluster::orderBy('id')->first());
        $page = $this->actingAs($crcc)->get(route('holidays.index'))->assertOk()->assertDontSee('Add a holiday on this date')->getContent();
        $cells = $this->dayCells($page);
        $this->assertSame([$empty->toDateString()], array_keys($cells));
        $this->assertNull($cells[$empty->toDateString()]['holidays'][0]['remove']);
        $this->assertSame([$empty->toDateString()], array_keys($this->dayCells($this->get(route('dashboard'))->getContent())));
    }

    public function test_day_popup_remove_only_for_the_district_managers_own_holidays(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $u = $this->districtAdmin($cluster);
        $day = today()->startOfMonth()->addDays(14)->toDateString();
        $state = Holiday::create(['date' => $day, 'name' => 'State Day', 'district_id' => null]);
        $local = Holiday::create(['date' => $day, 'name' => 'Local Day', 'district_id' => $cluster->block->district_id]);

        $hs = collect($this->dayCells($this->actingAs($u)->get(route('holidays.index'))->getContent())[$day]['holidays'])->keyBy('name');
        $this->assertNull($hs['State Day']['remove']);
        $this->assertSame(route('holidays.destroy', $local), $hs['Local Day']['remove']);
        // errors from the popup reopen it with the typed values
        $this->post(route('holidays.store'), ['_day' => 1, 'date' => $day, 'name' => 'State try'])->assertSessionHasErrors('district_id');
        $this->get(route('holidays.index'))->assertSee('data-reopen', false)->assertSee('value="State try"', false);
        $this->assertTrue($state->exists);
    }
}
