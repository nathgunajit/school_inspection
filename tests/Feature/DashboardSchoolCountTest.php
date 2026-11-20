<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Cluster;
use App\Models\School;
use Tests\TestCase;

/** Dashboard "Total schools in your …" tile: scoped to the officer's own area. */
class DashboardSchoolCountTest extends TestCase
{
    private function stats($user): array
    {
        return $this->actingAs($user)->get(route('dashboard'))->assertOk()->viewData('stats');
    }

    public function test_each_level_sees_the_schools_of_its_own_area(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $active = fn ($q) => $q->where('status', 'active')->count();

        $c = $this->stats($this->officer(UserLevel::Cluster, $cluster));
        $this->assertSame($active(School::where('cluster_id', $cluster->id)), $c['schools']);
        $this->assertGreaterThan(0, $c['schools']);

        $b = $this->stats($this->officer(UserLevel::Block, $cluster));
        $this->assertSame($active(School::where('block_id', $cluster->block_id)), $b['schools']);

        $d = $this->stats($this->officer(UserLevel::District, $cluster));
        $this->assertSame($active(School::where('district_id', $cluster->block->district_id)), $d['schools']);

        $a = $this->stats($this->admin());
        $this->assertSame($active(School::query()), $a['schools']);
        $this->assertGreaterThan($c['schools'], $a['schools']);

        $this->actingAs($this->officer(UserLevel::Cluster, $cluster))->get(route('dashboard'))
            ->assertSee('Total schools in your cluster');
    }

    public function test_inspected_this_month_counts_submitted_schools_only(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $i = $this->startInspection($officer, $this->schoolIn($cluster));

        $this->assertSame(0, $this->stats($officer)['schools_inspected_month'], 'a draft does not count');

        $i->forceFill(['status' => InspectionStatus::Submitted, 'submitted_at' => now()])->save();
        $this->assertSame(1, $this->stats($officer)['schools_inspected_month']);

        // an officer of another cluster does not see it
        $other = $this->officer(UserLevel::Cluster, $this->otherCluster($cluster));
        $this->assertSame(0, $this->stats($other)['schools_inspected_month']);
    }
}
