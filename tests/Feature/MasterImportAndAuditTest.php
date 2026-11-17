<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Exports\ArrayExport;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\School;
use App\Services\MasterImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class MasterImportAndAuditTest extends TestCase
{
    private function xlsx(array $rows, array $headings = MasterImportService::SCHOOL_COLUMNS): UploadedFile
    {
        $path = 'tmp/test-'.uniqid().'.xlsx';
        Excel::store(new ArrayExport($headings, $rows), $path, 'local');

        return new UploadedFile(Storage::disk('local')->path($path), 'schools.xlsx', null, null, true);
    }

    public function test_school_master_upload_adds_updates_and_reports_errors(): void
    {
        Storage::fake('private');
        Storage::fake('local');
        $admin = $this->admin();
        $existing = School::orderBy('id')->first();

        $file = $this->xlsx([
            [$existing->udise_code, 'Renamed School', '1801', '180101', '18010101', 'Department of Education', 'Primary (Class 1–5)', 'Addr', '26.1', '91.7', 'active'],
            ['18010101999', 'Brand New School', '1801', '180101', '18010101', 'Local Body', 'Upper Primary only (Class 6–8)', '', '', '', 'active'],
            ['12345', 'Short UDISE', '1801', '180101', '18010101', '', '', '', '', '', 'active'],
            ['18010101999', 'Duplicate In File', '1801', '180101', '18010101', '', '', '', '', '', 'active'],
            ['18010101998', 'Wrong Cluster', '1801', '180101', '18020101', '', '', '', '', '', 'active'],
            ['18010101997', '', '1801', '180101', '18010101', '', '', '', '', '', 'active'],
        ]);

        $this->actingAs($admin)->post(route('admin.imports.store'), ['type' => 'school', 'file' => $file])->assertRedirect(route('admin.imports.index'));

        $batch = ImportBatch::latest('id')->first();
        $this->assertSame(1, $batch->added);
        $this->assertSame(1, $batch->updated);
        $this->assertSame(4, $batch->rejected);
        $this->assertSame('Renamed School', $existing->fresh()->name);
        $this->assertDatabaseHas('schools', ['udise_code' => '18010101999', 'name' => 'Brand New School']);
        $this->assertNotNull($batch->error_file_path);
        Storage::disk('private')->assertExists($batch->error_file_path);
        $this->actingAs($admin)->get(route('admin.imports.errors', $batch))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'master_upload']);
    }

    public function test_district_and_block_coordinates_via_form_and_excel(): void
    {
        Storage::fake('private');
        Storage::fake('local');
        $admin = $this->admin();
        $district = \App\Models\District::where('code', '1801')->firstOrFail();

        // form: both-or-neither, range checked
        $this->actingAs($admin)->put(route('admin.locations.update', ['districts', $district->id]), [
            'code' => $district->code, 'name' => $district->name, 'state_id' => $district->state_id, 'latitude' => '26.1445',
        ])->assertSessionHasErrors('longitude');
        $this->actingAs($admin)->put(route('admin.locations.update', ['districts', $district->id]), [
            'code' => $district->code, 'name' => $district->name, 'state_id' => $district->state_id, 'latitude' => '126', 'longitude' => '91.7',
        ])->assertSessionHasErrors('latitude');
        $this->actingAs($admin)->put(route('admin.locations.update', ['districts', $district->id]), [
            'code' => $district->code, 'name' => $district->name, 'state_id' => $district->state_id, 'latitude' => '26.1445', 'longitude' => '91.7362',
        ])->assertSessionHas('success');
        $this->assertEqualsWithDelta(26.1445, $district->fresh()->latitude, 0.00001);
        $this->actingAs($admin)->get(route('admin.locations.index', 'districts'))->assertOk()->assertSee('26.14450');

        // Excel: optional columns; old 5-column files still work
        $cols = [...MasterImportService::LOCATION_COLUMNS, ...MasterImportService::LOCATION_OPTIONAL];
        $file = $this->xlsx([
            ['block', '180101', 'Riverside', '1801', 'active', '26.18', '91.75'],
            ['block', '180102', 'Hillview', '1801', 'active', '26.18', ''],
        ], $cols);
        $this->actingAs($admin)->post(route('admin.imports.store'), ['type' => 'location', 'file' => $file])->assertRedirect(route('admin.imports.index'));
        $batch = ImportBatch::latest('id')->first();
        $this->assertSame(1, $batch->updated);
        $this->assertSame(1, $batch->rejected);
        $this->assertEqualsWithDelta(91.75, \App\Models\Block::where('code', '180101')->value('longitude'), 0.00001);

        $old = $this->xlsx([['district', '1802', 'South District', '18', 'active']], MasterImportService::LOCATION_COLUMNS);
        $this->actingAs($admin)->post(route('admin.imports.store'), ['type' => 'location', 'file' => $old]);
        $this->assertSame(0, ImportBatch::latest('id')->first()->rejected);
    }

    public function test_wrong_template_is_refused(): void
    {
        Storage::fake('local');
        $file = $this->xlsx([['a', 'b']], ['foo', 'bar']);
        $this->actingAs($this->admin())->post(route('admin.imports.store'), ['type' => 'school', 'file' => $file])->assertSessionHasErrors('file');
    }

    public function test_masters_are_state_admin_only(): void
    {
        $this->actingAs($this->officer(UserLevel::District))->get(route('admin.imports.index'))->assertForbidden();
        $this->actingAs($this->officer(UserLevel::State))->get(route('admin.schools.index'))->assertForbidden();
    }

    public function test_audit_log_is_append_only_and_searchable_by_admin(): void
    {
        $admin = $this->admin();
        $this->post(route('login'), ['mobile' => $admin->mobile, 'password' => 'Secret@123']);
        $log = AuditLog::where('action', 'login')->firstOrFail();

        $this->assertThrows(fn () => $log->update(['action' => 'tampered']), LogicException::class);
        $this->assertThrows(fn () => $log->delete(), LogicException::class);

        $this->actingAs($admin)->get(route('admin.audit.index', ['action' => 'login']))->assertOk()->assertSee($admin->name);
        $this->actingAs($this->officer(UserLevel::State))->get(route('admin.audit.index'))->assertForbidden();
    }
}
