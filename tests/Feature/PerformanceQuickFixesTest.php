<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Cluster;
use App\Models\Inspection;
use App\Models\InspectionEvidence;
use App\Services\DashboardStats;
use App\Services\EvidenceStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** 26-11-2026 performance, module A: photo thumbnails, dashboard figure cache, cache:status rows. */
class PerformanceQuickFixesTest extends TestCase
{
    private function draft(): array
    {
        $cluster = Cluster::orderBy('id')->first();
        $officer = $this->officer(UserLevel::Cluster, $cluster);

        return [$officer, $this->startInspection($officer, $this->schoolIn($cluster))];
    }

    private function upload(Inspection $i, int $w = 1600, int $h = 1200)
    {
        $file = UploadedFile::fake()->image('p.jpg', $w, $h);

        return $this->post(route('evidence.store', $i), [
            'file' => $file, 'evidence_uid' => (string) Str::uuid(), 'kind' => 'photo', 'category' => 'school',
            'latitude' => 26.11, 'longitude' => 91.71, 'accuracy' => 9, 'sha256' => hash_file('sha256', $file->getRealPath()),
        ], ['Accept' => 'application/json']);
    }

    public function test_a_photo_gets_a_small_thumbnail_served_to_grids_and_removed_with_it(): void
    {
        if (! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is not installed');
        }
        Storage::fake('private');
        [, $i] = $this->draft();
        $json = $this->upload($i)->assertCreated()->json();
        $this->assertStringContainsString('size=thumb', $json['thumb_url']);

        $e = InspectionEvidence::firstOrFail();
        $thumb = EvidenceStorage::thumbPathFor($e->storage_path);
        Storage::disk('private')->assertExists($thumb);
        [$tw, $th] = getimagesizefromstring(Storage::disk('private')->get($thumb));
        $this->assertSame([EvidenceStorage::THUMB_SIDE, 270], [$tw, $th]); // 1600 x 1200 -> 360 x 270

        $small = $this->get($json['thumb_url'])->assertOk();
        $this->assertSame('image/jpeg', $small->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=86400', $small->headers->get('Cache-Control'));
        $full = $this->get($json['url'])->assertOk();
        $this->assertGreaterThan(strlen($small->streamedContent()), strlen($full->streamedContent()));

        // the step page grid and the review page tiles use the thumbnail; the viewer keeps the full photo
        $this->get(route('inspections.review', $i))->assertOk()->assertSee('size=thumb', false);

        $this->deleteJson(route('evidence.destroy', $e))->assertOk();
        Storage::disk('private')->assertMissing($e->storage_path);
        Storage::disk('private')->assertMissing($thumb);
    }

    public function test_missing_thumbnail_falls_back_to_the_photo_and_the_backfill_makes_it(): void
    {
        if (! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is not installed');
        }
        Storage::fake('private');
        [, $i] = $this->draft();
        $json = $this->upload($i, 800, 600)->assertCreated()->json();
        $e = InspectionEvidence::firstOrFail();
        Storage::disk('private')->delete(EvidenceStorage::thumbPathFor($e->storage_path)); // a photo from before thumbnails

        $this->get($json['thumb_url'])->assertOk(); // the original is served
        $this->artisan('evidence:thumbs')->expectsOutputToContain('1 made')->assertSuccessful();
        Storage::disk('private')->assertExists(EvidenceStorage::thumbPathFor($e->storage_path));
        $this->artisan('evidence:thumbs')->expectsOutputToContain('1 already there')->assertSuccessful();
    }

    public function test_dashboard_figures_are_cached_and_refreshed_by_a_submit(): void
    {
        [$officer, $i] = $this->draft();
        $stats = app(DashboardStats::class);
        $this->assertSame(1, $stats->for($officer)['my_drafts']);

        DB::enableQueryLog();
        $stats->for($officer);
        $this->assertCount(0, collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'count(')), 'served from the cache');
        DB::disableQueryLog();

        // a draft autosave (no status change) keeps the cache; a submit refreshes it
        $i->touch();
        $i->forceFill(['status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'T/1'])->save();
        $fresh = $stats->for($officer);
        $this->assertSame(0, $fresh['my_drafts']);
        $this->assertSame(1, $fresh['today']);
    }

    public function test_cache_status_shows_production_and_queue_rows(): void
    {
        $this->artisan('cache:status')
            ->expectsOutputToContain('Routes cached')
            ->expectsOutputToContain('GD (photo thumbnails, PDF photos)')
            ->expectsOutputToContain('Queue connection')
            ->assertSuccessful();
    }
}
