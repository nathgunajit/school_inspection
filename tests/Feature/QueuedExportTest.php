<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\ExportJob;
use App\Support\QueuedExports;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 26-11-2026 performance, module B: large downloads are made by the queue (the sync queue in tests runs the job at
 * once) and collected from the Downloads page by their owner only.
 */
class QueuedExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['inspection.export_queue.enabled' => true]);
        Storage::fake('private');
    }

    public function test_a_pdf_download_is_queued_made_and_served_to_its_owner_only(): void
    {
        $admin = $this->admin();
        $res = $this->actingAs($admin)->from(route('admin.schools.index'))
            ->get(route('admin.schools.export-pdf'), ['User-Agent' => 'QueueTest/1.0']);
        $res->assertRedirect(route('admin.schools.index'))->assertSessionHas('info')->assertSessionHas('export_job');

        $job = ExportJob::sole();
        $this->assertSame('done', $job->status, (string) $job->error);
        $this->assertSame('School Master (PDF)', $job->title);
        $this->assertStringEndsWith('.pdf', $job->file_name);
        $this->assertStringStartsWith('%PDF', Storage::disk('private')->get($job->file_path));

        // the export's own audit entry keeps the officer, IP and browser of the click
        $audit = AuditLog::where('action', 'school_list_exported')->sole();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('QueueTest/1.0', $audit->user_agent);

        // status JSON and the Downloads page
        $this->getJson(route('downloads.status'))->assertOk()->assertJsonPath('items.0.status', 'done')->assertJsonPath('items.0.url', route('downloads.file', $job));
        $this->get(route('downloads.index'))->assertOk()->assertSee('School Master (PDF)');
        $file = $this->get(route('downloads.file', $job))->assertOk();
        $this->assertStringContainsString('attachment', $file->headers->get('Content-Disposition'));
        $this->assertNotNull($job->fresh()->downloaded_at);
        $this->assertTrue(AuditLog::where('action', 'export_downloaded')->where('user_id', $admin->id)->exists());

        // another officer cannot see, download or delete it
        $other = $this->officer(UserLevel::District);
        $this->actingAs($other)->get(route('downloads.file', $job))->assertNotFound();
        $this->actingAs($other)->delete(route('downloads.destroy', $job))->assertNotFound();
        $this->actingAs($other)->getJson(route('downloads.status'))->assertOk()->assertJsonCount(0, 'items');

        // the owner removes it with its file
        $this->actingAs($admin)->delete(route('downloads.destroy', $job))->assertRedirect();
        Storage::disk('private')->assertMissing($job->file_path);
        $this->assertSame(0, ExportJob::count());
    }

    public function test_an_excel_download_is_stored_from_the_temporary_file(): void
    {
        $officer = $this->officer(UserLevel::Cluster, Cluster::orderBy('id')->first());
        $this->actingAs($officer)->from(route('schools.mine'))->get(route('schools.mine.export'))->assertRedirect(route('schools.mine'));
        $job = ExportJob::sole();
        $this->assertSame('done', $job->status, (string) $job->error);
        $this->assertStringEndsWith('.xlsx', $job->file_name);
        $this->assertSame('PK', substr(Storage::disk('private')->get($job->file_path), 0, 2)); // xlsx is a zip
        $this->assertGreaterThan(0, $job->size_bytes);
        $this->assertTrue(AuditLog::where('action', 'my_schools_exported')->where('user_id', $officer->id)->exists());
        // the officer is still signed in as themselves after the job ran inside the request
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame($officer->id, auth()->id());
    }

    public function test_a_refused_download_is_never_queued(): void
    {
        $crcc = $this->officer(UserLevel::Cluster, Cluster::orderBy('id')->first());
        $this->actingAs($crcc)->get(route('admin.schools.export'))->assertForbidden();      // route gate
        $district = $this->officer(UserLevel::District);
        $this->grant($district, 'export_excel');
        $this->actingAs($district)->get(route('reports.ratings.export'))->assertForbidden(); // controller middleware (State Admin only)
        $this->assertSame(0, ExportJob::count());
    }

    public function test_a_refusal_from_the_controller_is_shown_as_a_failed_download(): void
    {
        config(['inspection.master_pdf_max_rows' => 1]);
        $this->actingAs($this->admin())->from(route('admin.schools.index'))->get(route('admin.schools.export-pdf'))
            ->assertRedirect(route('admin.schools.index'))->assertSessionHas('warning');
        $job = ExportJob::sole();
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('A PDF holds up to 1', $job->error);
    }

    public function test_at_most_max_active_downloads_wait_at_once(): void
    {
        config(['inspection.export_queue.max_active' => 2]);
        $admin = $this->admin();
        foreach ([1, 2] as $n) {
            ExportJob::create(['user_id' => $admin->id, 'route_name' => 'admin.schools.export', 'url' => url('/x'), 'title' => 'T'.$n]);
        }
        $this->actingAs($admin)->from(route('admin.schools.index'))->get(route('admin.schools.export-pdf'))->assertSessionHas('warning');
        $this->assertSame(2, ExportJob::count());
    }

    public function test_without_the_queue_downloads_stay_direct(): void
    {
        config(['inspection.export_queue.enabled' => false]);
        $res = $this->actingAs($this->admin())->get(route('admin.schools.export-pdf'))->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame(0, ExportJob::count());
    }

    public function test_with_no_worker_running_the_download_is_made_at_once(): void
    {
        config(['queue.default' => 'database']);
        \Illuminate\Support\Facades\Cache::forget(QueuedExports::HEARTBEAT);
        $res = $this->actingAs($this->admin())->get(route('reports.ratings.export'))->assertOk();
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));
        $this->assertSame(0, ExportJob::count());
    }

    public function test_a_stalled_download_is_made_by_the_browser_once_and_by_its_owner_only(): void
    {
        config(['queue.default' => 'database']);
        QueuedExports::beat(); // a worker seems alive, so the click is queued
        $admin = $this->admin();
        $this->actingAs($admin)->from(route('dashboard'))->get(route('reports.ratings.export'))->assertRedirect();
        $job = ExportJob::sole();
        $this->assertSame('queued', $job->status);
        $this->assertNull($job->toClientArray()['run_url']);

        // not stalled yet: nothing runs
        $this->actingAs($admin)->postJson(route('downloads.run', $job))->assertStatus(409);
        $this->assertSame('queued', $job->fresh()->status);

        $job->forceFill(['created_at' => now()->subMinute()])->save();
        $this->assertNotNull($job->fresh()->toClientArray()['run_url']);
        $this->actingAs($this->officer(UserLevel::District))->postJson(route('downloads.run', $job))->assertNotFound();

        $this->actingAs($admin)->postJson(route('downloads.run', $job))->assertOk()->assertJsonPath('item.status', 'done');
        $job->refresh();
        $this->assertSame('done', $job->status, (string) $job->error);
        Storage::disk('private')->assertExists($job->file_path);

        // the worker finding it later does nothing
        $finished = $job->finished_at;
        (new \App\Jobs\GenerateExport($job->id))->handle();
        $this->assertEquals($finished, $job->fresh()->finished_at);
    }

    public function test_purge_removes_old_downloads_and_fails_stuck_ones(): void
    {
        $admin = $this->admin();
        Storage::disk('private')->put('exports/'.$admin->id.'/old.pdf', 'x');
        $old = ExportJob::create(['user_id' => $admin->id, 'route_name' => 'r', 'url' => url('/x'), 'title' => 'Old', 'status' => 'done',
            'file_path' => 'exports/'.$admin->id.'/old.pdf', 'file_name' => 'old.pdf']);
        $old->forceFill(['created_at' => now()->subHours(QueuedExports::keepHours() + 1)])->save();
        $stuck = ExportJob::create(['user_id' => $admin->id, 'route_name' => 'r', 'url' => url('/x'), 'title' => 'Stuck', 'status' => 'running']);
        $stuck->forceFill(['created_at' => now()->subHours(4)])->save();

        $this->artisan('exports:purge')->expectsOutputToContain('Removed 1 download(s)')->assertSuccessful();
        $this->assertNull(ExportJob::find($old->id));
        Storage::disk('private')->assertMissing('exports/'.$admin->id.'/old.pdf');
        $this->assertSame('failed', $stuck->fresh()->status);
    }
}
