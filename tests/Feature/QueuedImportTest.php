<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Exports\ArrayExport;
use App\Jobs\ImportMasterFile;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\ImportBatch;
use App\Models\School;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * 26-11-2026 performance, module C: the check and the save of an Excel upload run on the queue. Here the queue is
 * faked (as with a real worker, nothing runs in the request) and the jobs are run by hand.
 */
class QueuedImportTest extends TestCase
{
    private function xlsx(array $rows): UploadedFile
    {
        $path = 'tmp/test-'.uniqid().'.xlsx';
        Excel::store(new ArrayExport(['udise_code', 'school_name', 'cluster_code', 'status'], $rows), $path, 'local');

        return new UploadedFile(Storage::disk('local')->path($path), 'north.xlsx', null, null, true);
    }

    private function runJob(ImportBatch $batch, string $mode): void
    {
        Auth::forgetUser(); // a worker has no signed-in user
        (new ImportMasterFile($batch->id, $mode))->handle(app(\App\Services\MasterImportService::class));
    }

    public function test_check_and_save_run_in_the_background_and_the_page_follows_them(): void
    {
        Storage::fake('private');
        Storage::fake('local');
        Queue::fake();
        $admin = $this->admin();
        $north = District::where('code', '1801')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.imports.preview'), ['district_id' => $north->id, 'file' => $this->xlsx([['18010101931', 'Queued School', '18010101', 'active']])])
            ->assertRedirect(route('admin.imports.index'))->assertSessionHas('info');
        $batch = ImportBatch::sole();
        $this->assertSame('checking', $batch->status);
        Queue::assertPushed(ImportMasterFile::class, fn ($j) => $j->batchId === $batch->id && $j->mode === 'check');
        $this->get(route('admin.imports.index'))->assertOk()->assertSee('Checking the file…')->assertSee('data-import-status', false);
        $this->getJson(route('admin.imports.status'))->assertJson(['status' => 'checking']);

        $this->runJob($batch, 'check');
        $this->assertSame('checked', $batch->fresh()->status);
        $this->actingAs($admin)->get(route('admin.imports.index'))->assertSee('File checked: nothing is saved yet')->assertSee('Confirm and save 1 school');
        $this->assertDatabaseMissing('schools', ['udise_code' => '18010101931']);

        $this->post(route('admin.imports.confirm'))->assertRedirect(route('admin.imports.index'))->assertSessionHas('info');
        $this->assertSame('queued', $batch->fresh()->status);
        Queue::assertPushed(ImportMasterFile::class, fn ($j) => $j->batchId === $batch->id && $j->mode === 'import');

        $this->runJob($batch->fresh(), 'import');
        $done = $batch->fresh();
        $this->assertSame(['done', 1, null], [$done->status, $done->added, $done->pending_path]);
        $this->assertTrue(School::where('udise_code', '18010101931')->exists());
        Storage::disk('private')->assertDirectoryEmpty('imports/pending');
        // the audit entry names the officer although the worker had no signed-in user
        $this->assertSame($admin->id, AuditLog::where('action', 'master_upload')->sole()->user_id);

        // the page shows the result once, then a new upload; History lists the finished batch
        $this->actingAs($admin)->get(route('admin.imports.index'))->assertSee('Upload complete: 1 added');
        $this->get(route('admin.imports.index'))->assertDontSee('Upload complete')->assertDontSee('data-import-status', false);
        $this->get(route('admin.imports.index', ['tab' => 'history']))->assertSee('north.xlsx');
    }

    public function test_another_officer_cannot_confirm_or_see_someone_elses_upload(): void
    {
        Storage::fake('private');
        Storage::fake('local');
        Queue::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.imports.preview'), ['district_id' => District::where('code', '1801')->value('id'),
            'file' => $this->xlsx([['18010101932', 'X', '18010101', 'active']])]);
        $batch = ImportBatch::sole();
        $this->runJob($batch, 'check');

        $other = $this->officer(UserLevel::State);
        $this->grant($other, 'manage_masters');
        $this->actingAs($other)->withSession(['import_pending' => $batch->id]) // even with the id in their own session
            ->post(route('admin.imports.confirm'))->assertSessionHas('error');
        $this->assertSame('checked', $batch->fresh()->status);
        $this->getJson(route('admin.imports.status'))->assertJson(['status' => null]);
    }

    public function test_a_wrong_template_found_in_the_background_is_shown_as_an_error(): void
    {
        Storage::fake('private');
        Storage::fake('local');
        Queue::fake();
        $admin = $this->admin();
        $path = 'tmp/bad-'.uniqid().'.xlsx';
        Excel::store(new ArrayExport(['foo', 'bar'], [['a', 'b']]), $path, 'local');
        $this->actingAs($admin)->post(route('admin.imports.preview'), ['district_id' => 'all',
            'file' => new UploadedFile(Storage::disk('local')->path($path), 'bad.xlsx', null, null, true)]);
        $batch = ImportBatch::sole();
        $this->runJob($batch, 'check');
        $this->assertSame('check_failed', $batch->fresh()->status);
        $this->actingAs($admin)->get(route('admin.imports.index'))->assertSee('Missing columns');
        Storage::disk('private')->assertDirectoryEmpty('imports/pending');
    }

    public function test_purge_drops_unconfirmed_uploads(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        Storage::disk('private')->put('imports/pending/old.xlsx', 'x');
        $b = ImportBatch::create(['type' => 'school', 'status' => 'checked', 'uploaded_by' => $admin->id, 'file_name' => 'old.xlsx', 'pending_path' => 'imports/pending/old.xlsx']);
        ImportBatch::whereKey($b->id)->update(['updated_at' => now()->subDays(2)]);
        $this->artisan('exports:purge')->expectsOutputToContain('1 unconfirmed upload(s) removed')->assertSuccessful();
        $this->assertNull(ImportBatch::find($b->id));
        Storage::disk('private')->assertMissing('imports/pending/old.xlsx');
    }
}
