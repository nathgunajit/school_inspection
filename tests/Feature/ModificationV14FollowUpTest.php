<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\School;
use App\Services\CoverageReportService;
use App\Services\ScheduleService;
use Tests\TestCase;

/**
 * 23-11-2026 (V14 follow-up): My Inspections without Excel, Coverage downloads of the selected view,
 * school name / UDISE suggestions, and the schedule start point when no office location is set.
 */
class ModificationV14FollowUpTest extends TestCase
{
    public function test_my_inspections_has_no_excel_download_but_keeps_the_pdf(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $this->actingAs($officer)->get(route('inspections.mine'))->assertOk()
            ->assertDontSee('bi-file-earmark-excel', false)
            ->assertSee(route('inspections.export-pdf', 'mine'), false);
        $this->get('/export/mine')->assertNotFound();

        // 25-11-2026: Inspections in My Area has no Excel either, only the PDF
        $state = $this->officer(UserLevel::State);
        $this->actingAs($state)->get(route('inspections.area'))->assertOk()
            ->assertDontSee('bi-file-earmark-excel', false)
            ->assertSee(route('inspections.export-pdf', 'area'), false);
        $this->get('/export/area')->assertNotFound();
        $this->get(route('inspections.export-pdf', 'area'))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_coverage_excel_holds_only_the_selected_view(): void
    {
        $state = $this->officer(UserLevel::State);
        $svc = app(CoverageReportService::class);
        $district = District::orderBy('id')->first();
        $titles = fn (array $q) => array_map(fn ($s) => $s->title(),
            $svc->export($state, $svc->filters(\Illuminate\Http\Request::create('/', 'GET', $q), $state))->sheets());

        $this->assertSame(['Summary', 'District-wise', 'Schools not visited', 'Due in cycle'], $titles([]));
        $this->assertSame(['Summary', 'Block-wise', 'Schools not visited', 'Due in cycle'], $titles(['district_id' => $district->id]));
        $this->assertSame(['Summary', 'Cluster-wise', 'Schools not visited', 'Due in cycle'], $titles(['district_id' => $district->id, 'level' => 'cluster']));
        $cluster = Cluster::whereHas('block', fn ($q) => $q->where('district_id', $district->id))->orderBy('id')->first();
        $this->assertSame('Schools of the cluster', $titles(['cluster_id' => $cluster->id])[1]);

        // the buttons name the view and carry it
        $this->actingAs($state)->get(route('reports.coverage', ['district_id' => $district->id, 'level' => 'cluster']))->assertOk()
            ->assertSee('Excel · Cluster-wise')->assertSee('PDF · Cluster-wise');
        $xlsx = $this->get(route('reports.coverage.export', ['district_id' => $district->id, 'level' => 'cluster']))->assertOk();
        $this->assertStringContainsString('coverage-monthly-cluster-wise-', $xlsx->headers->get('content-disposition'));
        $this->assertSame('cluster', \App\Models\AuditLog::where('action', 'coverage_report_exported')->latest('id')->first()->meta['level']);
    }

    public function test_school_suggestions_match_name_and_udise_inside_the_jurisdiction(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $mine = $this->schoolIn($cluster);
        $other = $this->schoolIn($this->otherCluster($cluster));
        $officer = $this->officer(UserLevel::Cluster, $cluster);

        $items = fn (array $q) => collect($this->actingAs($officer)->getJson(route('schools.suggest', $q))->assertOk()->json('items'));
        $this->assertContains($mine->udise_code, $items(['q' => substr($mine->udise_code, 0, 6)])->pluck('udise'));
        $this->assertContains($mine->udise_code, $items(['q' => mb_substr($mine->name, 0, 5)])->pluck('udise'));
        $this->assertNotContains($other->udise_code, $items(['q' => $other->udise_code])->pluck('udise'), 'another cluster is out of scope');
        $this->assertCount(0, $items(['q' => 'a']));
        $this->assertLessThanOrEqual(10, $items(['q' => substr($mine->udise_code, 0, 2)])->count());

        // inactive schools: only for master managers asking for all
        $mine->update(['status' => 'closed']);
        $this->assertNotContains($mine->udise_code, $items(['q' => $mine->udise_code, 'all' => 1])->pluck('udise'));
        $admin = $this->admin();
        $this->assertContains($mine->udise_code, collect($this->actingAs($admin)->getJson(route('schools.suggest', ['q' => $mine->udise_code, 'all' => 1]))->json('items'))->pluck('udise'));

        // the search boxes use it
        $this->actingAs($admin)->get(route('admin.schools.index'))->assertOk()->assertSee('data-school-suggest', false);
        $this->actingAs($admin)->get(route('inspections.area'))->assertOk()->assertSee('data-school-suggest', false);
        $this->actingAs($officer)->get(route('schools.mine'))->assertOk()->assertSee('data-school-suggest', false);
        $this->get(route('inspections.mine'))->assertOk()->assertSee('data-school-suggest', false);
    }

    public function test_schedule_start_skips_offices_without_a_location(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $officer = $this->officer(UserLevel::Block, $cluster);
        Cluster::query()->update(['latitude' => null, 'longitude' => null]);
        Block::query()->update(['latitude' => null, 'longitude' => null]);
        District::query()->update(['latitude' => null, 'longitude' => null]);
        School::where('block_id', $cluster->block_id)->update(['latitude' => 26.5, 'longitude' => 91.5]);
        $svc = app(ScheduleService::class);

        $info = $svc->startInfo($officer);
        $this->assertSame('schools', $info['source']);
        $this->assertEqualsWithDelta(26.5, $info['point'][0], 0.0001);
        $this->assertStringContainsString('office location not set', $info['label']);

        $cluster->block->update(['latitude' => 26.2, 'longitude' => 91.8]);
        $info = $svc->startInfo($officer);
        $this->assertSame(['block', [26.2, 91.8]], [$info['source'], $info['point']]);
        $this->assertSame([26.2, 91.8], $svc->startPoint($officer));
        $this->assertStringStartsWith('Block office · ', $info['label']);
    }
}
