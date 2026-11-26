<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\CoverageSetting;
use App\Models\Inspection;
use App\Models\School;
use App\Models\User;
use App\Services\CoverageReportService;
use App\Support\CoveragePeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Inspection Coverage Report: configurable period, Visit Coverage (verified visit) vs Inspection Coverage (cycle). */
class CoverageReportTest extends TestCase
{
    private Cluster $north;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->north = Cluster::orderBy('id')->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An inspection row written directly (dates, status and locations are what the report reads). */
    private function visit(School $s, User $officer, string $date, string $status = 'submitted', ?array $at = null, ?array $submitAt = null): Inspection
    {
        $at ??= $s->hasCoordinates() ? [(float) $s->latitude, (float) $s->longitude] : [26.5, 92.5];

        return Inspection::create([
            'officer_id' => $officer->id, 'school_id' => $s->id, 'inspection_date' => $date, 'started_at' => $date.' 09:00:00',
            'submitted_at' => in_array($status, ['submitted', 'unlocked'], true) ? $date.' 11:00:00' : null,
            'district_id' => $s->district_id, 'block_id' => $s->block_id, 'cluster_id' => $s->cluster_id,
            'state_code' => '18', 'district_code' => 'D', 'block_code' => 'B', 'cluster_code' => 'C',
            'start_lat' => $at[0] ?? null, 'start_lng' => $at[1] ?? null,
            'submit_lat' => $submitAt[0] ?? null, 'submit_lng' => $submitAt[1] ?? null,
            'status' => $status, 'questionnaire_version' => 2,
        ]);
    }

    private function filters(User $user, array $q = []): array
    {
        return app(CoverageReportService::class)->filters(Request::create('/reports/coverage', 'GET', $q), $user);
    }

    private function summary(User $user, array $q = []): array
    {
        $svc = new CoverageReportService(app(\App\Services\JurisdictionService::class));

        return $svc->summary($user, $this->filters($user, $q));
    }

    private function schools(): array
    {
        return School::where('cluster_id', $this->north->id)->orderBy('id')->get()->all(); // 3 schools; the 3rd has no GPS
    }

    public function test_periods_and_cycles(): void
    {
        $ref = Carbon::parse('2026-11-20'); // a Friday
        $this->assertSame(['2026-11-20', '2026-11-20'], [CoveragePeriod::forType('daily', $ref)->fromDate(), CoveragePeriod::forType('daily', $ref)->toDate()]);
        $w = CoveragePeriod::forType('weekly', $ref);
        $this->assertSame(['2026-11-16', '2026-11-22'], [$w->fromDate(), $w->toDate()]);
        $this->assertSame('2026-11-09', $w->previous()->fromDate());
        $m = CoveragePeriod::forType('monthly', $ref);
        $this->assertSame(['2026-11-01', '2026-11-30', 'November 2026'], [$m->fromDate(), $m->toDate(), $m->label()]);
        $this->assertSame('2026-12-01', $m->next()->fromDate());
        $q = CoveragePeriod::forType('quarterly', $ref);
        $this->assertSame(['2026-10-01', '2026-12-31'], [$q->fromDate(), $q->toDate()]);
        $c = CoveragePeriod::custom(Carbon::parse('2026-11-10'), Carbon::parse('2026-11-01'));
        $this->assertSame(['2026-11-01', '2026-11-10', 10], [$c->fromDate(), $c->toDate(), $c->days()]);
        $this->assertSame('2026-11-11', $c->next()->fromDate());

        $this->assertSame(['2026-10-01', '2027-03-31'], [CoveragePeriod::cycle('half_yearly', $ref)->fromDate(), CoveragePeriod::cycle('half_yearly', $ref)->toDate()]);
        $this->assertSame(['2026-04-01', '2027-03-31'], [CoveragePeriod::cycle('yearly', $ref)->fromDate(), CoveragePeriod::cycle('yearly', $ref)->toDate()]);
        $this->assertSame('2026-10-01', CoveragePeriod::cycle('quarterly', $ref)->fromDate());
    }

    public function test_visit_coverage_counts_unique_verified_visits_and_inspection_coverage_uses_the_cycle(): void
    {
        [$s1, $s2, $s3] = $this->schools();
        $o = $this->officer(UserLevel::Cluster, $this->north);
        $o2 = $this->officer(UserLevel::Block, $this->north);

        $this->visit($s1, $o, '2026-11-03');
        $this->visit($s1, $o2, '2026-11-12');                                  // repeat visit, same school
        $far = $this->visit($s2, $o, '2026-11-05', 'draft', [(float) $s2->latitude + 1, (float) $s2->longitude]); // 100+ km away: unverified
        $this->visit($s3, $o, '2026-11-06', 'cancelled');                       // cancelled: ignored
        $this->visit($s3, $o2, '2026-10-15');                                  // before the month: not a visit, but inside the quarter cycle

        $admin = $this->admin();
        $q = ['cluster_id' => $this->north->id];
        $s = $this->summary($admin, $q);
        $this->assertSame(3, $s['schools']);
        $this->assertSame(1, $s['visited']);
        $this->assertSame(2, $s['visits']);
        $this->assertSame(1, $s['repeat']);
        $this->assertSame(1, $s['unverified']);
        $this->assertSame(2, $s['not_visited']);
        $this->assertSame(33.3, $s['visit_pct']);
        $this->assertSame(2, $s['inspected']);          // s1 (Nov) and s3 (Oct), both submitted in Q4
        $this->assertSame(66.7, $s['insp_pct']);
        $this->assertSame(2, $s['officers']);

        // a geo-tagged photo near the school verifies the far visit
        DB::table('inspection_evidence')->insert(['inspection_id' => $far->id, 'evidence_uid' => (string) \Illuminate\Support\Str::uuid(), 'kind' => 'photo',
            'storage_path' => 'x.jpg', 'original_name' => 'x.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64),
            'latitude' => $s2->latitude, 'longitude' => $s2->longitude, 'status' => 'uploaded', 'uploaded_by' => $o->id, 'created_at' => now(), 'updated_at' => now()]);
        $s = $this->summary($admin, $q);
        $this->assertSame(2, $s['visited']);
        $this->assertSame(0, $s['unverified']);
        $this->assertSame(2, $s['inspected'], 'a draft is a visit but not a formal inspection');

        // a school without GPS in the master: any captured location verifies
        $this->visit($s3, $o, '2026-11-18', 'submitted', [10.0, 10.0]);
        $this->assertSame(3, $this->summary($admin, $q)['visited']);
        $this->assertSame(100.0, $this->summary($admin, $q)['visit_pct']);

        // no location at all: unverified
        $s1->update(['latitude' => null, 'longitude' => null]);
        $this->visit($s1, $o, '2026-11-19', 'submitted', [null, null]);
        $this->assertSame(1, $this->summary($admin, $q)['unverified']);
    }

    public function test_radius_setting_changes_verification(): void
    {
        [$s1] = $this->schools();
        $o = $this->officer(UserLevel::Cluster, $this->north);
        $this->visit($s1, $o, '2026-11-03', 'submitted', [(float) $s1->latitude + 0.009, (float) $s1->longitude]); // ~1 km
        $admin = $this->admin();
        $this->assertSame(0, $this->summary($admin, ['cluster_id' => $this->north->id])['visited']);
        CoverageSetting::state()->update(['verify_radius_m' => 1500]);
        $this->assertSame(1, $this->summary($admin, ['cluster_id' => $this->north->id])['visited']);
    }

    public function test_area_rows_list_every_area_sorted_by_unique_visits(): void
    {
        $o = $this->officer(UserLevel::Cluster, $this->north);
        $other = Cluster::where('id', '!=', $this->north->id)->where('block_id', $this->north->block_id)->firstOrFail();
        foreach (School::where('cluster_id', $other->id)->get() as $i => $sch) {
            $this->visit($sch, $o, '2026-11-0'.($i + 1));
        }
        $this->visit($this->schools()[0], $o, '2026-11-09');

        $admin = $this->admin();
        $svc = app(CoverageReportService::class);
        $f = $this->filters($admin, ['block_id' => $this->north->block_id]);
        $this->assertSame(['cluster'], $svc->levels($admin, $f));
        $rows = $svc->areas($admin, $f, 'cluster');
        $this->assertSame([$other->id, $this->north->id], $rows->pluck('id')->all());
        $this->assertSame([3, 1], $rows->pluck('visited')->all());

        $districts = $svc->areas($admin, $this->filters($admin), 'district');
        $this->assertCount(2, $districts, 'districts without visits are listed too');
        $this->assertSame(0, $districts->last()['visited']);

        $byName = $svc->areas($admin, $this->filters($admin, ['block_id' => $this->north->block_id, 'sort' => 'name']), 'cluster');
        $this->assertSame($byName->pluck('name')->sort()->values()->all(), $byName->pluck('name')->all());
    }

    public function test_page_and_school_list_scoped_to_the_officer(): void
    {
        [$s1] = $this->schools();
        $o = $this->officer(UserLevel::Cluster, $this->north);
        $this->visit($s1, $o, '2026-11-03');

        // cluster officer: own cluster and its schools, not visited first
        $res = $this->actingAs($o)->get(route('reports.coverage'))->assertOk()
            ->assertSee('Inspection Coverage Report')->assertSee('November 2026')->assertSee('Visit coverage')->assertSee('Inspection coverage');
        $this->assertSame([], $res->viewData('levels'));
        $schools = $res->viewData('schools');
        $this->assertCount(3, $schools);
        $this->assertSame(0, $schools->first()['verified']);
        $this->assertSame($s1->id, $schools->last()['school']->id);

        // a district officer cannot look into another district
        $district = $this->officer(UserLevel::District, $this->north);
        $foreign = $this->otherCluster($this->north);
        $res = $this->actingAs($district)->get(route('reports.coverage', ['district_id' => $foreign->block->district_id]))->assertOk();
        $this->assertSame($this->north->block->district_id, $res->viewData('filters')['district_id']);
        $this->assertSame(['block', 'cluster'], $res->viewData('levels'));
        $this->assertSame(1, $res->viewData('summary')['visited']);

        // state admin: district-wise, weekly view on request
        $admin = $this->admin();
        $res = $this->actingAs($admin)->get(route('reports.coverage', ['type' => 'weekly']))->assertOk()->assertSee('Week 16–22 Nov 2026');
        $this->assertSame('district', $res->viewData('level'));
        $this->actingAs($admin)->get(route('dashboard'))->assertSee('Reports')->assertSee(route('reports.coverage'), false); // 25-11-2026: one Reports item
    }

    /** 26-11-2026: the figures are explained in plain sentences on the page, the settings page and the downloads. */
    public function test_report_and_settings_explain_the_figures_in_plain_words(): void
    {
        [$s1] = $this->schools();
        $o = $this->officer(UserLevel::Cluster, $this->north);
        $this->visit($s1, $o, '2026-11-03');

        $res = $this->actingAs($o)->get(route('reports.coverage'))->assertOk()
            ->assertSee('What this report shows')->assertSee('Schools in this area')->assertSee('Questions about this report')
            ->assertSee('Good 75 % and above · Moderate 50–74 % · Low 25–49 % · Critical below 25 %');
        $say = $res->viewData('explain');
        $this->assertStringStartsWith('1 of 3 schools (33.3 %)', $say['visit']);
        $this->assertStringContainsString('November 2026', $say['visit']);
        $this->assertStringContainsString('2 schools were not visited.', $say['visit']);
        $this->assertStringContainsString('1 of 3 schools (33.3 %) have a submitted inspection in the current quarterly cycle (01 Oct 2026 – 31 Dec 2026). 2 schools are still due.', $say['inspection']);
        $res->assertSee($say['visit']);

        // downloads carry the same sentences in a "What it means" column
        $rows = app(CoverageReportService::class)->export($o, $this->filters($o))->sheets()[0];
        $this->assertSame(CoverageReportService::SUMMARY_HEADS, $rows->headings());
        $byItem = collect($rows->array())->keyBy(0);
        $this->assertSame($say['visit'], $byItem['Visit Coverage'][2]);
        $this->assertSame($say['inspection'], $byItem['Inspection Coverage'][2]);
        $pdf = app(CoverageReportService::class)->pdfSections($o, $this->filters($o));
        $this->assertSame(CoverageReportService::SUMMARY_HEADS, $pdf[0]['headings']);

        // settings: field help, the dates each choice gives, and a plain success message
        $admin = $this->admin();
        $res = $this->actingAs($admin)->get(route('reports.coverage.settings'))->assertOk()
            ->assertSee('Period and cycle: what is the difference?')->assertSee('How often every school must have a');
        $preview = $res->viewData('preview');
        $this->assertSame('this month (01 Nov 2026 – 30 Nov 2026)', $preview['periods']['monthly']);
        $this->assertSame('01 Oct 2026 – 31 Dec 2026', $preview['cycles']['quarterly']);
        $this->assertSame('01 Apr 2026 – 31 Mar 2027', $preview['cycles']['yearly']);
        $this->actingAs($admin)->put(route('reports.coverage.settings.update'), ['scope' => 'state', 'period_type' => 'monthly', 'inspection_cycle' => 'quarterly'])
            ->assertSessionHas('success', 'Coverage settings saved. The report now opens on this month (01 Nov 2026 – 30 Nov 2026). A school counts as inspected when an inspection is submitted in the quarterly cycle (01 Oct 2026 – 31 Dec 2026).');
    }

    /** 26-11-2026: the running quarter with a monthly cycle used December (not started yet), so Inspection coverage was 0 %. */
    public function test_a_running_period_uses_the_current_cycle_not_a_future_one(): void
    {
        [$s1] = $this->schools();
        $o = $this->officer(UserLevel::Cluster, $this->north);
        $this->visit($s1, $o, '2026-11-10');
        $districtId = $this->north->block->district_id;
        CoverageSetting::create(['district_id' => $districtId, 'period_type' => 'monthly', 'inspection_cycle' => 'monthly', 'verify_radius_m' => 500]);
        $svc = app(CoverageReportService::class);

        // today is 20-11-2026: the current quarter ends 31-12, so the cycle is the current month
        $f = $this->filters($o, ['type' => 'quarterly']);
        $this->assertSame(['2026-11-01', '2026-11-30'], [$svc->cycle($f)->fromDate(), $svc->cycle($f)->toDate()]);
        $this->assertSame(1, $this->summary($o, ['type' => 'quarterly'])['inspected']);

        // a finished quarter keeps the cycle of its last day
        $past = $this->filters($o, ['type' => 'quarterly', 'ref' => '2026-08-15']);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$svc->cycle($past)->fromDate(), $svc->cycle($past)->toDate()]);

        // the state default (quarterly cycle) still gives the whole quarter
        $other = $this->officer(UserLevel::Cluster, $this->otherCluster($this->north));
        $g = $this->filters($other, ['type' => 'quarterly']);
        $this->assertSame(['2026-10-01', '2026-12-31'], [$svc->cycle($g)->fromDate(), $svc->cycle($g)->toDate()]);
    }

    public function test_settings_state_default_and_district_override(): void
    {
        $admin = $this->admin();
        $district = $this->officer(UserLevel::District, $this->north);
        $districtId = $this->north->block->district_id;
        $other = $this->otherCluster($this->north)->block->district_id;

        // without Manage users: no access; with it: own district only
        $this->actingAs($district)->get(route('reports.coverage.settings'))->assertForbidden();
        $this->grant($district, \App\Models\Permission::MANAGE_USERS);
        app()->forgetInstance(\App\Services\PermissionService::class);
        $district = $district->fresh();
        $this->actingAs($district)->get(route('reports.coverage.settings'))->assertOk()->assertDontSee('Visit verification radius');
        $this->actingAs($district)->put(route('reports.coverage.settings.update'), ['scope' => 'state', 'period_type' => 'daily', 'inspection_cycle' => 'monthly'])->assertForbidden();
        $this->actingAs($district)->put(route('reports.coverage.settings.update'), ['scope' => 'district', 'district_id' => $other, 'period_type' => 'daily', 'inspection_cycle' => 'monthly'])->assertForbidden();
        $this->actingAs($district)->put(route('reports.coverage.settings.update'), ['scope' => 'district', 'period_type' => 'quarterly', 'inspection_cycle' => 'monthly'])->assertRedirect();

        $this->assertSame('quarterly', CoverageSetting::forDistrict($districtId)->period_type);
        $this->assertSame('monthly', CoverageSetting::forDistrict($other)->period_type, 'other districts keep the state default');
        $res = $this->actingAs($district)->get(route('reports.coverage'))->assertOk()->assertSee('Q4 · Oct–Dec 2026');
        $this->assertSame('monthly', $res->viewData('setting')->inspection_cycle);

        // state admin: weekly default, radius, custom validation, reset the district
        $this->actingAs($admin)->put(route('reports.coverage.settings.update'), ['scope' => 'state', 'period_type' => 'custom', 'custom_from' => '2026-11-10', 'custom_to' => '2026-11-01', 'inspection_cycle' => 'quarterly'])
            ->assertSessionHasErrors('custom_to');
        $this->actingAs($admin)->put(route('reports.coverage.settings.update'), ['scope' => 'state', 'period_type' => 'weekly', 'inspection_cycle' => 'yearly', 'verify_radius_m' => 300])->assertRedirect();
        $this->assertSame(['weekly', 300], [CoverageSetting::state()->period_type, CoverageSetting::state()->verify_radius_m]);
        $this->actingAs($admin)->get(route('reports.coverage', ['district_id' => $other]))->assertOk()->assertSee('Week 16–22 Nov 2026');
        $this->actingAs($admin)->put(route('reports.coverage.settings.update'), ['scope' => 'district', 'district_id' => $districtId, 'reset' => 1])->assertRedirect();
        $this->assertSame('weekly', CoverageSetting::forDistrict($districtId)->period_type);
        $this->assertSame(3, AuditLog::whereIn('action', ['coverage_setting_updated', 'coverage_setting_reset'])->count());

        // block / cluster officers never configure
        $this->actingAs($this->officer(UserLevel::Block, $this->north))->get(route('reports.coverage.settings'))->assertForbidden();
    }

    /** 25-11-2026: the Excel and PDF hold the period picked on screen and name it (title, Summary, file name). */
    public function test_downloads_hold_and_name_the_selected_period(): void
    {
        [$s1, $s2] = $this->schools();
        $o = $this->officer(UserLevel::Cluster, $this->north);
        $this->visit($s1, $o, '2026-11-10');
        $this->visit($s2, $o, '2026-11-03');
        $admin = $this->admin();
        $svc = app(CoverageReportService::class);
        $summary = function (array $q) use ($svc, $admin) {
            $rows = $svc->export($admin, $this->filters($admin, $q))->sheets()[0]->array();

            return array_column($rows, 1, 0);
        };

        $daily = $summary(['type' => 'daily', 'ref' => '2026-11-10']);
        $this->assertSame('Daily', $daily['Report period type']);
        $this->assertStringContainsString('10-11-2026 to 10-11-2026', $daily['Coverage period']);
        $this->assertSame(1, $daily['Schools visited'], 'only the visit of that day');

        $monthly = $summary(['type' => 'monthly', 'ref' => '2026-11-10']);
        $this->assertSame('Monthly', $monthly['Report period type']);
        $this->assertStringContainsString('01-11-2026 to 30-11-2026', $monthly['Coverage period']);
        $this->assertSame(2, $monthly['Schools visited']);

        $xlsx = $this->actingAs($admin)->get(route('reports.coverage.export', ['type' => 'daily', 'ref' => '2026-11-10']))->assertOk();
        $this->assertStringContainsString('coverage-daily-district-wise-2026-11-10-to-2026-11-10.xlsx', $xlsx->headers->get('content-disposition'));
        $this->assertSame('daily', AuditLog::where('action', 'coverage_report_exported')->latest('id')->first()->meta['type']);
        $pdf = $this->get(route('reports.coverage.export-pdf', ['type' => 'monthly', 'ref' => '2026-11-10']))->assertOk();
        $this->assertStringContainsString('coverage-monthly-district-wise-2026-11-01-to-2026-11-30.pdf', $pdf->headers->get('content-disposition'));
    }

    public function test_excel_export_and_permissions(): void
    {
        $o = $this->officer(UserLevel::Cluster, $this->north);
        $this->visit($this->schools()[0], $o, '2026-11-03');
        $admin = $this->admin();
        $xlsx = $this->actingAs($admin)->get(route('reports.coverage.export'))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('content-type'));
        $this->assertSame(1, AuditLog::where('action', 'coverage_report_exported')->count());

        $role = \App\Models\Role::where('slug', 'cluster')->first();
        $role->permissions()->detach(\App\Models\Permission::where('slug', \App\Models\Permission::EXPORT_EXCEL)->value('id'));
        app()->forgetInstance(\App\Services\PermissionService::class);
        $this->actingAs($o->fresh())->get(route('reports.coverage.export'))->assertForbidden();
        $role->permissions()->detach(\App\Models\Permission::where('slug', \App\Models\Permission::VIEW_REPORTS)->value('id'));
        app()->forgetInstance(\App\Services\PermissionService::class);
        $this->actingAs($o->fresh())->get(route('reports.coverage'))->assertForbidden();
    }
}
