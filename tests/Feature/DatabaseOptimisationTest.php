<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Cluster;
use App\Models\CoverageSetting;
use App\Models\UserJurisdiction;
use App\Services\JurisdictionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** 25-11-2026: database optimisation (Product Details/DB_INDEXING.md section 9). */
class DatabaseOptimisationTest extends TestCase
{
    public function test_new_school_indexes_exist(): void
    {
        foreach (['schools_status_deleted_name_idx', 'schools_status_deleted_area_idx'] as $name) {
            $this->assertTrue(Schema::hasIndex('schools', $name), $name);
        }
    }

    public function test_posting_and_coverage_setting_are_read_once_per_request_and_forgotten_on_change(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $j = app(JurisdictionService::class);

        DB::enableQueryLog();
        $first = $j->current($officer);
        $j->current($officer);
        $j->current($officer);
        CoverageSetting::forDistrict($cluster->block->district_id);
        CoverageSetting::forDistrict($cluster->block->district_id);
        $reads = collect(DB::getQueryLog())->pluck('query');
        $this->assertSame(1, $reads->filter(fn ($q) => str_contains($q, 'user_jurisdictions'))->count());
        $this->assertLessThanOrEqual(2, $reads->filter(fn ($q) => str_contains($q, 'coverage_settings'))->count()); // district row, then state default

        // a transfer is seen in the same request
        $other = $this->otherCluster($cluster);
        $first->update(['end_date' => today()->toDateString()]);
        UserJurisdiction::create(['user_id' => $officer->id, 'state_id' => $first->state_id, 'district_id' => $other->block->district_id,
            'block_id' => $other->block_id, 'cluster_id' => $other->id, 'start_date' => today()->addDay()->toDateString()]);
        $this->assertSame($other->id, $j->current($officer)->cluster_id);
    }

    public function test_db_maintain_says_nothing_to_do_on_sqlite(): void
    {
        $this->artisan('db:maintain')->expectsOutputToContain('Nothing to do on sqlite')->assertSuccessful();
    }

    public function test_my_schools_keeps_visit_counts_and_never_inspected_first(): void
    {
        $officer = $this->officer(UserLevel::Cluster, Cluster::orderBy('id')->first());
        $this->actingAs($officer)->get(route('schools.mine'))->assertOk()->assertViewHas('schools', function ($p) {
            $visits = collect($p->items())->pluck('visits')->map(fn ($v) => (int) $v)->all();
            $sorted = $visits;
            sort($sorted);

            return $visits !== [] && $visits === $sorted; // active schools, fewest visits first
        });
    }
}
