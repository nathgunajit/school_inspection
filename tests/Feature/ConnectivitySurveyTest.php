<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Inspection;
use App\Models\InspectionAnswer;
use App\Models\School;
use App\Services\ConnectivitySurvey;
use Tests\TestCase;

/**
 * 28-11-2026: internet connectivity per school (ConnectivitySurvey, from NET_01), on the Map View "Internet" colour
 * and the school page.
 */
class ConnectivitySurveyTest extends TestCase
{
    private function net(Inspection $i, string $value, string $date, ?string $remark = null, ?InspectionStatus $status = null): Inspection
    {
        $i->forceFill(['inspection_date' => $date, 'status' => $status ?? InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'TEST/'.$i->id])->save();
        InspectionAnswer::create(['inspection_id' => $i->id, 'step' => 1, 'question_code' => 'NET_01', 'value' => $value, 'remarks' => $remark]);

        return $i;
    }

    public function test_latest_answer_decides_and_cancelled_ones_are_ignored(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $cluster = $officer->currentJurisdiction->cluster;
        $school = $this->schoolIn($cluster);
        $other = School::where('cluster_id', $cluster->id)->where('id', '!=', $school->id)->orderBy('id')->firstOrFail();

        $first = $this->net($this->startInspection($officer, $school), 'yes', now()->subDays(10)->toDateString(), 'No signal');
        $copy = fn (int $daysAgo) => tap($first->replicate(['verify_token', 'inspection_no'])->forceFill(['inspection_date' => now()->subDays($daysAgo)->toDateString()]))->save();
        $this->net($copy(2), 'no', now()->subDays(2)->toDateString());
        // a later cancelled inspection saying "no internet" does not count
        $this->net($copy(1), 'yes', now()->subDay()->toDateString(), null, InspectionStatus::Cancelled);

        $out = app(ConnectivitySurvey::class)->forSchools($officer, [$school->id, $other->id]);
        $this->assertSame('ok', $out[$school->id]['state']);
        $this->assertSame(1, $out[$school->id]['reports']);
        $this->assertSame('No signal', $out[$school->id]['last_remark']);
        $this->assertArrayNotHasKey($other->id, $out, 'not surveyed');

        $this->assertSame(['no' => 0, 'ok' => 1], app(ConnectivitySurvey::class)->areaCounts($officer, 'cluster_id', [$cluster->id])[$cluster->id]);
    }

    public function test_cluster_officer_does_not_see_a_block_officers_answer(): void
    {
        $cluster = $this->officer(UserLevel::Cluster);
        $block = $this->officer(UserLevel::Block, $cluster->currentJurisdiction->cluster);
        $school = $this->schoolIn($cluster->currentJurisdiction->cluster);
        $this->net($this->startInspection($block, $school), 'yes', now()->toDateString());

        $this->assertArrayHasKey($school->id, app(ConnectivitySurvey::class)->forSchools($block, [$school->id]));
        $this->assertSame([], app(ConnectivitySurvey::class)->forSchools($cluster, [$school->id]));
    }

    public function test_map_and_school_page_show_connectivity(): void
    {
        config(['inspection.map.cache_seconds' => 0]);
        $admin = $this->admin();
        $officer = $this->officer(UserLevel::Cluster);
        $cluster = $officer->currentJurisdiction->cluster;
        $school = $this->schoolIn($cluster);
        $this->net($this->startInspection($officer, $school), 'yes', now()->toDateString(), 'Tower down');

        $this->actingAs($admin);
        $period = ['from' => now()->subMonth()->toDateString(), 'to' => now()->toDateString()];
        $schools = $this->getJson(route('map.schools', ['cluster' => $cluster->id] + $period))->assertOk()->json();
        $row = collect($schools['rows'])->firstWhere('id', $school->id);
        $this->assertSame('no', $row['net']['state']);
        $this->assertSame(1, $schools['totals']['net_no']);
        $this->assertSame(count($schools['rows']) - 1, $schools['totals']['net_unknown']);

        $areas = $this->getJson(route('map.areas', ['level' => 'cluster', 'parent' => $cluster->block_id] + $period))->assertOk()->json();
        $area = collect($areas['rows'])->firstWhere('id', $cluster->id);
        $this->assertSame(1, $area['net']['no']);
        $this->assertSame(0, $area['net']['ok']);
        $this->assertSame(100.0, (float) $area['net']['no_pct']);

        $this->get(route('map.index', ['colour' => 'net']))->assertOk()->assertSee('id="colourNet" value="net" checked', false);
        $this->get(route('schools.show', $school))->assertOk()->assertSee('No internet')->assertSee('Tower down');

        $other = School::where('cluster_id', $cluster->id)->where('id', '!=', $school->id)->orderBy('id')->firstOrFail();
        $this->get(route('schools.show', $other))->assertOk()->assertSee('Not surveyed');
    }
}
