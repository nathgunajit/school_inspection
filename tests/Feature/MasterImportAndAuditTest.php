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
