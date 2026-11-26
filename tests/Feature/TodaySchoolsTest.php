<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\DailyDisplaySetting;
use App\Models\DailySchoolList;
use App\Models\Holiday;
use App\Models\Inspection;
use App\Models\Permission;
use App\Models\School;
use App\Services\DailySchoolListService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** V14 item 5 (25-11-2026): Today's schools, with the admin settings. */
class TodaySchoolsTest extends TestCase
{
    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cluster = Cluster::orderBy('id')->firstOrFail();
        $base = School::where('cluster_id', $this->cluster->id)->orderBy('id')->firstOrFail();
        foreach ([1, 2] as $n) { // five schools in the cluster
            $base->replicate()->fill(['udise_code' => '1899999999'.$n, 'name' => 'Extra School '.$n])->save();
        }
        DailyDisplaySetting::state()->update(['weekly_off' => []]); // the tests must not depend on the weekday
    }

    private ?\App\Models\User $crccUser = null;

    /** 25-11-2026: one CRCC per test (their own inspections are the ones their list can see) */
    private function crcc()
    {
        return $this->crccUser ??= $this->officer(UserLevel::Cluster, $this->cluster);
    }

    /** A submitted inspection of a school, $daysAgo days ago, by the CRCC (since 25-11-2026 a CRCC sees only own and lower-level inspections). */
    private function inspected(School $school, int $daysAgo, ?float $score = null): Inspection
    {
        $other = $this->crcc();
        $i = $this->startInspection($other, $school);
        $i->forceFill(['status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_date' => today()->subDays($daysAgo)->toDateString(),
            'inspection_no' => 'T/'.$school->id.'/'.$daysAgo])->save();
        if ($score !== null) {
            $scheme = app(\App\Services\RatingService::class)->activeScheme()->id;
            DB::table('inspection_ratings')->insert(['inspection_id' => $i->id, 'scheme_id' => $scheme, 'obtained' => $score, 'applicable' => 100,
                'score_percent' => $score, 'sections' => '[]', 'details' => '[]', 'strengths' => '[]', 'weaknesses' => '[]', 'calculated_at' => now(),
                'created_at' => now(), 'updated_at' => now()]);
        }

        return $i;
    }

    public function test_list_is_made_once_with_the_daily_limit_and_shown_on_the_dashboard(): void
    {
        $crcc = $this->crcc();
        $this->actingAs($crcc)->get(route('dashboard'))->assertOk()->assertSee("Today's schools", false);
        $rows = DailySchoolList::where('officer_id', $crcc->id)->where('list_date', today()->toDateString())->with('school')->get();
        $this->assertCount(2, $rows); // default limit 2
        $this->assertTrue($rows->every(fn ($r) => $r->reason === 'not_inspected' && $r->source === 'auto'));

        $this->get(route('today.index'))->assertOk()->assertSee($rows[0]->school->name);
        $this->assertSame(2, DailySchoolList::where('officer_id', $crcc->id)->count(), 'generated only once');
        $this->assertSame(1, AuditLog::where('action', 'daily_list_generated')->count());

        // a Block officer does not get a list by default
        $block = $this->officer(UserLevel::Block, $this->cluster);
        $this->actingAs($block)->get(route('today.index'))->assertOk()->assertSee('not switched on');
        $this->assertSame(0, DailySchoolList::where('officer_id', $block->id)->count());
    }

    public function test_priority_never_inspected_then_low_rating_and_recent_ones_left_out(): void
    {
        $schools = School::where('cluster_id', $this->cluster->id)->where('status', 'active')->orderBy('id')->get();
        DailyDisplaySetting::state()->update(['daily_limit' => 10, 'prefer_issues' => false]);
        // all but one inspected long ago; one of them with a low rating; one inspected last week (recent: left out)
        foreach ($schools->slice(1) as $s) {
            $this->inspected($s, 200, 80);
        }
        $low = $schools[1];
        $this->inspected($low, 100, 20); // latest rating 20 %
        $recent = $schools[2];
        $this->inspected($recent, 5);

        $crcc = $this->crcc();
        $picks = app(DailySchoolListService::class)->pick($crcc, today(), DailyDisplaySetting::state()->fresh(), [], 10);
        $this->assertSame([$schools[0]->id, 'not_inspected'], $picks[0]);
        $this->assertSame([$low->id, 'low_rating'], $picks[1]);
        $this->assertNotContains($recent->id, array_column($picks, 0));
        $this->assertSame('less_visited', $picks[2][1]);

        DailyDisplaySetting::state()->update(['selection' => 'random']);
        $this->assertSame('random', app(DailySchoolListService::class)->pick($crcc, today(), DailyDisplaySetting::state()->fresh(), [], 1)[0][1]);
    }

    public function test_pending_schools_carry_forward_and_submit_marks_visited(): void
    {
        $crcc = $this->crcc();
        $s = School::where('cluster_id', $this->cluster->id)->orderBy('id')->first();
        DailySchoolList::create(['officer_id' => $crcc->id, 'list_date' => today()->subDays(2)->toDateString(), 'school_id' => $s->id,
            'position' => 1, 'reason' => 'not_inspected', 'source' => 'auto', 'status' => 'pending']);

        $this->actingAs($crcc)->get(route('today.index'))->assertOk()->assertSee('Pending from earlier');
        $today = DailySchoolList::where('officer_id', $crcc->id)->where('list_date', today()->toDateString())->orderBy('position')->get();
        $this->assertCount(3, $today); // 26-11-2026: the carried school is extra to the daily limit of 2
        $this->assertSame(2, $today->where('source', 'auto')->count());
        $this->assertSame(['carried', $s->id], [$today[0]->source, $today[0]->school_id]);
        $this->assertSame(today()->subDays(2)->toDateString(), $today[0]->carried_from);
        $this->assertSame('carried', DailySchoolList::where('list_date', today()->subDays(2)->toDateString())->value('status'));

        // submitting marks it visited
        $i = $this->startInspection($crcc, $s);
        app(DailySchoolListService::class)->markVisited($i->fresh());
        $this->assertSame('visited', $today[0]->fresh()->status);
        $this->assertSame($i->id, $today[0]->fresh()->inspection_id);

        // skip with a reason
        $this->actingAs($crcc)->post(route('today.skip', $today[1]), ['reason' => 'School closed for flood'])->assertRedirect();
        $this->assertSame('skipped', $today[1]->fresh()->status);
        $outsider = $this->officer(UserLevel::Cluster, $this->otherCluster($this->cluster));
        $this->actingAs($outsider)->post(route('today.skip', $today[1]), ['reason' => 'xxx'])->assertForbidden();
    }

    /** 26-11-2026: two pending schools with the daily limit 2 used to leave no room for new ones. */
    public function test_pending_schools_are_shown_on_top_of_the_new_ones(): void
    {
        $crcc = $this->crcc();
        $old = School::where('cluster_id', $this->cluster->id)->orderBy('id')->take(2)->get();
        foreach ($old as $n => $s) {
            DailySchoolList::create(['officer_id' => $crcc->id, 'list_date' => today()->subDay()->toDateString(), 'school_id' => $s->id,
                'position' => $n + 1, 'reason' => 'not_inspected', 'source' => 'auto', 'status' => 'pending']);
        }

        $this->actingAs($crcc)->get(route('today.index'))->assertOk()->assertSee('+ pending from earlier');
        $today = DailySchoolList::where('officer_id', $crcc->id)->where('list_date', today()->toDateString())->get();
        $this->assertSame(['auto' => 2, 'carried' => 2], $today->countBy('source')->sortKeys()->all());
        $this->assertSame($today->count(), $today->pluck('school_id')->unique()->count(), 'no school twice');
        $this->assertEqualsCanonicalizing($old->pluck('id')->all(), $today->where('source', 'carried')->pluck('school_id')->all());
    }

    public function test_a_day_made_before_the_fix_gets_its_new_schools(): void
    {
        $crcc = $this->crcc();
        $day = today()->toDateString();
        $s = School::where('cluster_id', $this->cluster->id)->orderBy('id')->first();
        // the old result: the marker is there and only the carried school is on the list
        DB::table('daily_school_list_days')->insert(['officer_id' => $crcc->id, 'list_date' => $day, 'created_at' => now(), 'updated_at' => now()]);
        DailySchoolList::create(['officer_id' => $crcc->id, 'list_date' => $day, 'school_id' => $s->id, 'position' => 1, 'reason' => 'carried',
            'source' => 'carried', 'status' => 'pending', 'carried_from' => today()->subDay()->toDateString()]);

        $this->assertSame(1, DailySchoolListService::reopenCarriedOnlyDays($day));
        $this->actingAs($crcc)->get(route('today.index'))->assertOk();
        $today = DailySchoolList::where('officer_id', $crcc->id)->where('list_date', $day)->get();
        $this->assertSame(['auto' => 2, 'carried' => 1], $today->countBy('source')->sortKeys()->all());
        $this->assertSame(0, DailySchoolListService::reopenCarriedOnlyDays($day), 'a completed day is left alone');
    }

    public function test_no_list_on_a_holiday_or_weekly_off(): void
    {
        $crcc = $this->crcc();
        Holiday::create(['date' => today()->toDateString(), 'name' => 'Ali-Ai-Ligang', 'district_id' => $this->cluster->block->district_id]);
        $this->actingAs($crcc)->get(route('today.index'))->assertOk()->assertSee('Holiday: Ali-Ai-Ligang');
        $this->get(route('dashboard'))->assertOk()->assertSee('Holiday: Ali-Ai-Ligang');
        $this->assertSame(0, DailySchoolList::count());

        Holiday::all()->each->delete();
        DailyDisplaySetting::state()->update(['weekly_off' => [(int) today()->dayOfWeek]]);
        $this->get(route('today.index'))->assertOk()->assertSee('Weekly off');
        $this->assertSame(0, DailySchoolList::count());
    }

    public function test_higher_officer_assigns_schools_manually(): void
    {
        $crcc = $this->crcc();
        $block = $this->officer(UserLevel::Block, $this->cluster);
        $ids = School::where('cluster_id', $this->cluster->id)->orderByDesc('id')->take(3)->pluck('id')->all();

        $this->actingAs($block)->get(route('today.assign', ['officer' => $crcc->id]))->assertOk()->assertSee('Assign ticked schools');
        $this->post(route('today.assign.store'), ['officer_id' => $crcc->id, 'date' => today()->toDateString(), 'school_ids' => $ids])->assertRedirect();
        $this->assertSame(3, DailySchoolList::where('officer_id', $crcc->id)->where('source', 'manual')->where('assigned_by', $block->id)->count());
        $this->assertTrue(AuditLog::where('action', 'daily_list_assigned')->exists());

        // manual rows fill the limit, so no automatic schools are added
        $this->actingAs($crcc)->get(route('today.index'))->assertOk()->assertSee('Assigned')->assertSee($block->name);
        $this->assertSame(3, DailySchoolList::where('officer_id', $crcc->id)->count());

        // a CRCC cannot assign, and nobody can assign outside their area
        $this->actingAs($crcc)->get(route('today.assign'))->assertForbidden();
        $far = $this->officer(UserLevel::Block, $this->otherCluster($this->cluster));
        $this->actingAs($far)->post(route('today.assign.store'), ['officer_id' => $crcc->id, 'date' => today()->toDateString(), 'school_ids' => $ids])->assertForbidden();

        // remove an assigned school
        $row = DailySchoolList::where('officer_id', $crcc->id)->first();
        $this->actingAs($block)->delete(route('today.remove', $row))->assertRedirect();
        $this->assertNull(DailySchoolList::find($row->id));
    }

    public function test_settings_state_default_and_district_override(): void
    {
        $admin = $this->admin();
        $payload = ['enabled' => 1, 'levels' => ['cluster', 'block'], 'daily_limit' => 3, 'selection' => 'priority', 'low_rating_below' => 60,
            'recent_days' => 15, 'weekly_off' => [0, 6], 'prefer_not_inspected' => 1, 'carry_forward' => 1];
        $this->actingAs($admin)->get(route('today.settings'))->assertOk()->assertSee('State default');
        $this->put(route('today.settings.update'), ['scope' => 'state'] + $payload)->assertRedirect();
        $state = DailyDisplaySetting::state()->fresh();
        $this->assertSame([3, ['cluster', 'block'], [0, 6], false], [$state->daily_limit, $state->levels, $state->weekly_off, $state->prefer_issues]);

        $districtId = $this->cluster->block->district_id;
        $d = $this->officer(UserLevel::District, $this->cluster);
        $this->grant($d, Permission::MANAGE_USERS);
        $this->actingAs($d->fresh())->get(route('today.settings'))->assertOk()->assertSee('follows the state default');
        $this->put(route('today.settings.update'), ['scope' => 'state', 'district_id' => 999] + array_merge($payload, ['daily_limit' => 1]))->assertRedirect();
        $this->assertSame(1, DailyDisplaySetting::forDistrict($districtId)->daily_limit); // always their own district
        $this->assertSame(3, DailyDisplaySetting::state()->fresh()->daily_limit);
        $this->assertTrue(AuditLog::where('action', 'daily_settings_updated')->exists());

        $this->put(route('today.settings.update'), ['reset' => 1])->assertRedirect();
        $this->assertSame(3, DailyDisplaySetting::forDistrict($districtId)->daily_limit);

        $this->actingAs($this->crcc())->get(route('today.settings'))->assertForbidden();
    }

    /** 25-11-2026: a District user chooses only the Cluster / Block officers; District / State officers follow the State. */
    public function test_district_user_chooses_block_and_cluster_only(): void
    {
        $payload = ['enabled' => 1, 'daily_limit' => 2, 'selection' => 'priority', 'low_rating_below' => 50, 'recent_days' => 30, 'weekly_off' => [0]];
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('today.settings.update'), ['scope' => 'state', 'levels' => ['cluster', 'district']] + $payload)->assertRedirect();

        $districtId = $this->cluster->block->district_id;
        $d = $this->officer(UserLevel::District, $this->cluster);
        $this->grant($d, Permission::MANAGE_USERS);
        // untouched district: the State's levels exactly
        $this->assertSame(['cluster', 'district'], DailyDisplaySetting::forDistrict($districtId)->levels);

        $html = $this->actingAs($d->fresh())->get(route('today.settings'))->assertOk()
            ->assertSee('District and State officers follow the State default')
            ->assertSee('name="levels[]" value="cluster" id="lv0cluster" checked', false)
            ->assertSee('name="levels[]" value="block" id="lv0block"', false)->getContent();
        $this->assertStringNotContainsString('value="district" id="lv0district"', $html);
        $this->assertStringNotContainsString('value="state" id="lv0state"', $html);

        // a crafted "state" is dropped; their Block choice is kept, District officers come from the State
        $this->put(route('today.settings.update'), ['levels' => ['block', 'state']] + array_merge($payload, ['daily_limit' => 1]))->assertRedirect();
        $row = DailyDisplaySetting::where('district_id', $districtId)->firstOrFail();
        $this->assertSame(['block'], $row->levels);
        $this->assertSame(['block', 'district'], DailyDisplaySetting::forDistrict($districtId)->levels);

        $svc = app(DailySchoolListService::class);
        $this->assertTrue($svc->appliesTo($this->officer(UserLevel::Block, $this->cluster)));
        $this->assertFalse($svc->appliesTo($this->crcc()));

        // a later State change of the upper levels reaches the district; its own Block choice stays
        DailyDisplaySetting::state()->update(['levels' => ['cluster', 'state']]);
        $this->assertSame(['block', 'state'], DailyDisplaySetting::forDistrict($districtId)->levels);

        // the State Admin's district form follows the same rule
        $this->actingAs($admin)->put(route('today.settings.update'), ['scope' => 'district', 'district_id' => $districtId, 'levels' => ['cluster', 'district']] + $payload)->assertRedirect();
        $this->assertSame(['cluster'], $row->fresh()->levels);
    }

    public function test_open_draft_shows_continue_on_the_row_until_submitted(): void
    {
        $crcc = $this->crcc();
        $this->actingAs($crcc)->get(route('today.index'))->assertOk();
        $row = DailySchoolList::where('officer_id', $crcc->id)->where('list_date', today()->toDateString())->orderBy('position')->firstOrFail();

        $draft = $this->startInspection($crcc, $row->school);
        $draft->forceFill(['completion_pct' => 40])->save();
        $this->assertSame(InspectionStatus::Draft, $draft->fresh()->status);

        $html = $this->get(route('today.index'))->assertOk()->assertSee('Draft in progress · 40%')
            ->assertSee(route('inspections.step', [$draft, 1]), false)->assertSee('is-draft', false)->getContent();
        $this->assertStringNotContainsString(route('today.skip', $row), $html, 'no Skip while a draft is open');
        $this->get(route('dashboard'))->assertOk()->assertSee('Draft 40%');
        $this->assertSame('pending', $row->fresh()->status, 'the school stays on the list');

        app(DailySchoolListService::class)->markVisited($draft->fresh());
        $this->get(route('today.index'))->assertOk()->assertSee('Visited')->assertDontSee('Draft in progress');
    }

    public function test_open_issues_raise_the_priority(): void
    {
        $schools = School::where('cluster_id', $this->cluster->id)->where('status', 'active')->orderBy('id')->get();
        DailyDisplaySetting::state()->update(['daily_limit' => 10, 'prefer_not_inspected' => false, 'prefer_low_rating' => false, 'prefer_less_visited' => false]);
        foreach ($schools as $s) {
            $this->inspected($s, 100);
        }
        $flagged = $schools[3];
        $latest = Inspection::where('school_id', $flagged->id)->firstOrFail();
        $latest->answers()->create(['step' => 1, 'question_code' => 'NET_01', 'value' => 'yes']);
        $latest->answers()->create(['step' => 1, 'question_code' => 'CND_07', 'value' => 'no']);

        $picks = app(DailySchoolListService::class)->pick($this->crcc(), today(), DailyDisplaySetting::state()->fresh(), [], 10);
        $this->assertSame([$flagged->id, 'issue'], $picks[0]);
        $this->assertSame('random', $picks[1][1]); // nothing else stands out
    }
}
