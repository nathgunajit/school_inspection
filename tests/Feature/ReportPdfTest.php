<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\AuditLog;
use Tests\TestCase;

/** 23-11-2026: PDF downloads of Inspections, My Inspections and the Coverage Report (App\Support\MasterPdf). */
class ReportPdfTest extends TestCase
{
    private function submitted($officer, $school)
    {
        $i = $this->startInspection($officer, $school);
        $i->forceFill(['status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'T/'.$i->id])->save();

        return $i;
    }

    private function assertPdf($res): void
    {
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_my_inspections_and_area_pdfs_are_scoped_audited_and_capped(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $cluster = $officer->currentJurisdiction->cluster;
        $this->submitted($officer, $this->schoolIn($cluster));
        $other = $this->officer(UserLevel::Cluster, $this->otherCluster($cluster));
        $this->submitted($other, $this->schoolIn($this->otherCluster($cluster)));

        $this->actingAs($officer)->get(route('inspections.mine'))->assertOk()->assertSee(route('inspections.export-pdf', 'mine'), false);
        $this->assertPdf($this->get(route('inspections.export-pdf', 'mine')));
        $log = AuditLog::where('action', 'inspections_exported')->latest('id')->first();
        $this->assertSame(['pdf', 'mine', 1], [$log->meta['format'], $log->meta['mode'], $log->meta['rows']]);

        // a Cluster officer's area PDF holds only their own inspections
        $this->assertPdf($this->get(route('inspections.export-pdf', 'area')));
        $this->assertSame(1, AuditLog::where('action', 'inspections_exported')->latest('id')->first()->meta['rows']);

        // a State officer sees both
        $this->assertPdf($this->actingAs($this->officer(UserLevel::State))->get(route('inspections.export-pdf', 'area')));
        $this->assertSame(2, AuditLog::where('action', 'inspections_exported')->latest('id')->first()->meta['rows']);

        $this->get(route('inspections.export-pdf', 'other'))->assertNotFound();

        config(['inspection.master_pdf_max_rows' => 1]);
        $this->from(route('inspections.area'))->get(route('inspections.export-pdf', 'area'))
            ->assertRedirect(route('inspections.area'))->assertSessionHas('warning');
    }

    public function test_coverage_pdf_follows_the_view_and_is_capped(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $this->submitted($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $state = $this->officer(UserLevel::State);

        $this->actingAs($state)->get(route('reports.coverage'))->assertOk()->assertSee(route('reports.coverage.export-pdf'), false);
        $this->assertPdf($this->get(route('reports.coverage.export-pdf')));
        $this->assertTrue(AuditLog::where('action', 'coverage_report_exported')->get()->contains(fn ($a) => ($a->meta['format'] ?? null) === 'pdf'));

        // one cluster: the school list
        $cluster = $officer->currentJurisdiction->cluster;
        $this->assertPdf($this->get(route('reports.coverage.export-pdf', ['district_id' => $cluster->block->district_id, 'block_id' => $cluster->block_id, 'cluster_id' => $cluster->id])));

        config(['inspection.master_pdf_max_rows' => 0]);
        $this->from(route('reports.coverage'))->get(route('reports.coverage.export-pdf'))
            ->assertRedirect(route('reports.coverage'))->assertSessionHas('warning');
    }

    public function test_pdf_sections_hold_the_summary_and_the_level_in_view(): void
    {
        $state = $this->officer(UserLevel::State);
        $service = app(\App\Services\CoverageReportService::class);
        $f = $service->filters(request(), $state);
        $sections = $service->pdfSections($state, $f);

        $this->assertSame(['Summary', 'District-wise'], array_column($sections, 'title'));
        $this->assertSame('Total', end($sections[1]['rows'])[1]);
        $this->assertCount(count(\App\Services\CoverageReportService::AREA_HEADS_SHORT), $sections[1]['rows'][0]);
    }
}
