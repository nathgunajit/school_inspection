<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\School;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** 23-11-2026: School, District, Block and Cluster Masters download as Excel or PDF, with the screen's filters. */
class MasterExportTest extends TestCase
{
    public function test_location_masters_download_as_excel_with_the_filters(): void
    {
        $this->actingAs($this->admin());
        $district = District::orderBy('id')->first();
        $date = now()->format('Y-m-d');
        Excel::fake();

        $this->get(route('admin.locations.export', ['districts', 'excel']))->assertOk();
        Excel::assertDownloaded("district_master_$date.xlsx", fn ($e) => count($e->array()) === District::count()
            && $e->headings()[0] === 'Sl' && in_array('Clusters', $e->headings(), true)
            && $e->array()[0][8] === School::where('district_id', District::orderBy('name')->orderBy('id')->first()->id)->count());

        $this->get(route('admin.locations.export', ['blocks', 'excel', 'parent' => $district->id]))->assertOk();
        Excel::assertDownloaded("block_master_$date.xlsx", fn ($e) => count($e->array()) === Block::where('district_id', $district->id)->count()
            && collect($e->array())->every(fn ($r) => $r[3] === $district->label));

        $block = Block::orderBy('id')->first();
        $this->get(route('admin.locations.export', ['clusters', 'excel', 'parent' => $block->id]))->assertOk();
        Excel::assertDownloaded("cluster_master_$date.xlsx", fn ($e) => count($e->array()) === Cluster::where('block_id', $block->id)->count()
            && $e->array()[0][3] === $block->label);

        $this->assertDatabaseHas('audit_logs', ['action' => 'master_exported']);
        $this->get(route('admin.locations.export', ['districts', 'csv']))->assertNotFound();
    }

    public function test_location_masters_download_as_pdf(): void
    {
        $this->actingAs($this->admin());
        foreach (['districts', 'blocks', 'clusters'] as $type) {
            $res = $this->get(route('admin.locations.export', [$type, 'pdf']))->assertOk();
            $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $res->getContent());
        }
        $this->get(route('admin.locations.index', 'clusters'))->assertSee(route('admin.locations.export', ['clusters', 'pdf']), false);
    }

    public function test_school_master_pdf_follows_the_filters_and_has_a_row_limit(): void
    {
        $this->actingAs($this->admin());
        $district = District::orderBy('id')->first();
        $res = $this->get(route('admin.schools.export-pdf', ['district_id' => $district->id]))->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'school_list_exported']);
        $this->get(route('admin.schools.index'))->assertSee(route('admin.schools.export-pdf'), false);

        config(['inspection.master_pdf_max_rows' => 1]);
        $this->from(route('admin.schools.index'))->get(route('admin.schools.export-pdf'))
            ->assertRedirect(route('admin.schools.index'))->assertSessionHas('warning');
    }

    public function test_the_html_view_lists_rows_and_filters(): void
    {
        $html = view('admin.exports.master-pdf', ['title' => 'Cluster Master', 'headings' => ['Sl', 'Code', 'Name'], 'rows' => [[1, '1801010', 'GOSSAINICHINA']],
            'filters' => ['Block: DOTMA (180101)'], 'logos' => ['assam' => null, 'ssa' => null], 'generatedAt' => now()])->render();
        foreach (['CLUSTER MASTER', 'GOSSAINICHINA', 'Block: DOTMA (180101)', 'class="pdf-wm"', 'Records <b>1</b>'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_officers_without_manage_masters_cannot_download(): void
    {
        $this->actingAs($this->officer(UserLevel::Cluster));
        $this->get(route('admin.locations.export', ['districts', 'excel']))->assertForbidden();
        $this->get(route('admin.schools.export-pdf'))->assertForbidden();
    }
}
