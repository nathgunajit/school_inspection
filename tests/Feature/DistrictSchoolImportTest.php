<?php

namespace Tests\Feature;

use App\Exports\ArrayExport;
use App\Models\District;
use App\Models\ImportBatch;
use App\Models\School;
use App\Services\MasterImportService;
use App\Services\SchoolCountReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** District-wise School Master upload (no District / Block columns), preview → confirm, district report. */
class DistrictSchoolImportTest extends TestCase
{
    /** The older district-file order: files are read by heading name, so any column order still imports. */
    private const OLD_DISTRICT_COLUMNS = ['udise_code', 'school_name', 'cluster_code', 'management', 'category', 'address', 'latitude', 'longitude', 'status'];

    private function xlsx(array $rows, array $headings = self::OLD_DISTRICT_COLUMNS): UploadedFile
    {
        $path = 'tmp/test-'.uniqid().'.xlsx';
        Excel::store(new ArrayExport($headings, $rows), $path, 'local');

        return new UploadedFile(Storage::disk('local')->path($path), 'north.xlsx', null, null, true);
    }

    private function north(): District
    {
        return District::where('code', '1801')->firstOrFail();
    }

    public function test_preview_saves_nothing_then_confirm_imports_with_block_and_district_from_cluster(): void
    {
        Storage::fake('private');
        Storage::fake('local');
        $admin = $this->admin();
        $north = $this->north();
        $file = $this->xlsx([
            ['18010101901', 'New North School A', '18010101', '', '', '', '', '', 'active'],
            ['18010201902', 'New North School B', '18010201', '', '', '', '', '', 'active'],
            ['18020101903', 'South Cluster School', '18020101', '', '', '', '', '', 'active'],
            ['123', 'Bad UDISE', '18010101', '', '', '', '', '', 'active'],
        ]);

        $this->actingAs($admin)->post(route('admin.imports.preview'), ['district_id' => $north->id, 'file' => $file])
            ->assertRedirect(route('admin.imports.index'))->assertSessionHas('import_preview', fn ($p) => $p['added'] === 2 && $p['rejected'] === 2 && count($p['summary']['blocks']) >= 2);
        $this->assertDatabaseMissing('schools', ['udise_code' => '18010101901']);
        $this->assertSame(0, ImportBatch::count());

        $page = $this->get(route('admin.imports.index'))->assertOk();
        $page->assertSee('File checked: nothing is saved yet')->assertSee('Confirm and save 2 schools')->assertSee('is not in the chosen district (North District)', false);

        $this->post(route('admin.imports.confirm'))->assertRedirect(route('admin.imports.index'));
        $s = School::where('udise_code', '18010201902')->firstOrFail();
        $this->assertSame($north->id, $s->district_id);
        $this->assertSame('18010201', $s->cluster->code);
        $this->assertSame($s->cluster->block_id, $s->block_id);
        $this->assertDatabaseMissing('schools', ['udise_code' => '18020101903']);

        $batch = ImportBatch::latest('id')->first();
        $this->assertSame($north->id, $batch->district_id);
        $this->assertSame([2, 0, 2], [$batch->added, $batch->updated, $batch->rejected]);
        $this->assertNotEmpty($batch->summary['blocks']);
        Storage::disk('private')->assertDirectoryEmpty('imports/pending');

        // the checked file is used once
        $this->post(route('admin.imports.confirm'))->assertSessionHas('error');
    }

    public function test_district_is_required_for_a_school_check(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin())->post(route('admin.imports.preview'), ['file' => $this->xlsx([])])->assertSessionHasErrors('district_id');
    }

    public function test_cancel_discards_the_checked_file(): void
    {
        Storage::fake('private');
        Storage::fake('local');
        $this->actingAs($this->admin())->post(route('admin.imports.preview'), ['district_id' => $this->north()->id,
            'file' => $this->xlsx([['18010101905', 'X', '18010101', '', '', '', '', '', 'active']])]);
        $this->post(route('admin.imports.cancel'))->assertRedirect();
        Storage::disk('private')->assertDirectoryEmpty('imports/pending');
        $this->post(route('admin.imports.confirm'))->assertSessionHas('error');
        $this->assertDatabaseMissing('schools', ['udise_code' => '18010101905']);
    }

    public function test_district_template_has_a_cluster_sheet(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.imports.template', ['school', 'district' => $this->north()->id]))->assertOk();
        $this->assertStringContainsString('school_master_north-district.xlsx', $res->headers->get('content-disposition'));

        $export = MasterImportService::districtTemplate($this->north());
        $sheets = $export->sheets();
        $this->assertCount(5, $sheets); // Schools, Clusters, AC list, PC list, Value lists
        $this->assertSame(MasterImportService::SCHOOL_COLUMNS, $sheets[0]->headings());
        $codes = array_column($sheets[1]->array(), 0);
        $this->assertContains('18010101', $codes);
        $this->assertNotContains('18020101', $codes, 'only clusters of the district');
    }

    public function test_district_report_counts_schools_and_exports(): void
    {
        $admin = $this->admin();
        $north = $this->north();
        $row = app(SchoolCountReport::class)->districts()->firstWhere('id', $north->id);
        $this->assertSame(School::where('district_id', $north->id)->count(), $row->schools);
        $this->assertSame(School::where('district_id', $north->id)->where('status', 'active')->count(), $row->active);

        $blocks = $this->actingAs($admin)->getJson(route('admin.imports.district', ['district' => $north->id]))->assertOk()->json('blocks');
        $this->assertSame($row->schools, array_sum(array_column($blocks, 'schools')));

        $this->get(route('admin.imports.index', ['tab' => 'report']))->assertOk()->assertSee('Schools by district')->assertSee($north->name);
        $this->get(route('admin.imports.report'))->assertOk()->assertDownload();
    }
}
