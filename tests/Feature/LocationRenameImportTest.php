<?php

namespace Tests\Feature;

use App\Exports\ArrayExport;
use App\Models\Block;
use App\Models\District;
use App\Models\ImportBatch;
use App\Models\School;
use App\Services\MasterImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** Location Master upload corrects names by code: IDs and links stay, parents are never changed. */
class LocationRenameImportTest extends TestCase
{
    private const COLS = [...MasterImportService::LOCATION_COLUMNS, ...MasterImportService::LOCATION_OPTIONAL];

    private function xlsx(array $rows): UploadedFile
    {
        $path = 'tmp/test-'.uniqid().'.xlsx';
        Excel::store(new ArrayExport(self::COLS, $rows), $path, 'local');

        return new UploadedFile(Storage::disk('local')->path($path), 'locations.xlsx', null, null, true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Storage::fake('local');
    }

    public function test_rename_is_previewed_then_saved_keeping_id_and_links(): void
    {
        $admin = $this->admin();
        $d = District::where('code', '1801')->firstOrFail();
        $b = Block::where('code', '180101')->firstOrFail();
        $schools = School::where('district_id', $d->id)->count();
        $state = $d->state->code;

        $file = $this->xlsx([
            ['district', '1801', 'North District (Corrected)', $state, 'active', '', ''],
            ['block', '180101', 'Riverside Block East', '1801', 'active', '', ''],
            ['block', '180102', Block::where('code', '180102')->value('name'), '1801', 'active', '', ''], // unchanged
        ]);

        $this->actingAs($admin)->post(route('admin.imports.preview'), ['type' => 'location', 'file' => $file])
            ->assertSessionHas('import_preview', fn ($p) => $p['summary']['renamed_count'] === 2 && $p['summary']['unchanged'] === 1 && $p['updated'] === 2);
        $this->assertSame('North District', $d->fresh()->name, 'nothing saved by the check');
        $this->get(route('admin.imports.index'))->assertSee('Name corrections')->assertSee('North District (Corrected)')->assertSee('Confirm and save 2 changes');

        $this->post(route('admin.imports.confirm'))->assertRedirect(route('admin.imports.index'));
        $this->assertSame('North District (Corrected)', District::find($d->id)->name);
        $this->assertSame('Riverside Block East', Block::find($b->id)->name);
        $this->assertSame($d->id, Block::find($b->id)->district_id);
        $this->assertSame($schools, School::where('district_id', $d->id)->count(), 'schools still linked by ID');
        $this->assertSame(2, District::count(), 'no new district created');

        $batch = ImportBatch::latest('id')->first();
        $this->assertSame([0, 2, 0], [$batch->added, $batch->updated, $batch->rejected]);
        $this->assertSame(2, $batch->summary['renamed_count']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'location_renamed']);
        $this->get(route('admin.imports.index', ['tab' => 'history']))->assertSee('2 renamed');
    }

    public function test_a_different_parent_is_rejected_and_nothing_moves(): void
    {
        $admin = $this->admin();
        $b = Block::where('code', '180101')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.imports.preview'), ['type' => 'location', 'file' => $this->xlsx([
            ['block', '180101', 'Riverside Block', '1802', 'active', '', ''],
        ])])->assertSessionHas('import_preview', fn ($p) => $p['rejected'] === 1 && str_contains($p['errors'][0]['reason'], 'changing the parent is not allowed'));

        $this->post(route('admin.imports.confirm'));
        $this->assertSame($b->district_id, $b->fresh()->district_id);
    }

    public function test_current_locations_download_lists_existing_codes(): void
    {
        $rows = MasterImportService::currentLocations()->array();
        $codes = array_column($rows, 1);
        foreach (['1801', '180101', '18010101'] as $c) {
            $this->assertContains($c, $codes);
        }
        $this->actingAs($this->admin())->get(route('admin.imports.template', ['location', 'current' => 1]))->assertOk()->assertDownload();
    }
}
