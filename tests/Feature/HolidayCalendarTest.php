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
}
