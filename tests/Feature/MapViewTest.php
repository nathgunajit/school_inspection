<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\School;
use Tests\TestCase;

/** New Module 1: interactive Map View, counts, status colours and jurisdiction scoping. */
class MapViewTest extends TestCase
{
    private function submitted(\App\Models\Inspection $i): void
    {
        $i->update(['status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'SI/MAP/'.$i->id]);
    }

    public function test_page_opens_for_every_officer_level(): void
    {
        foreach ([UserLevel::StateAdmin, UserLevel::State, UserLevel::District, UserLevel::Block, UserLevel::Cluster] as $level) {
            $this->actingAs($this->officer($level))->get(route('map.index'))
                ->assertOk()->assertSee('id="map"', false)->assertSee('Map View')
                // Modification V8 items 2 and 4: colour key on top of the map, compact filter fields
                ->assertSee('map-keybar', false)->assertSee('data-key="inspected"', false)->assertSee('Not inspected')
                ->assertSee('form-select form-select-sm', false)->assertSee('map-filters', false)
                // 25-11-2026: no Map view button on this page; #mapCard is the dashboard button's target
                ->assertDontSee('data-map-jump', false)->assertSee('id="mapCard"', false);
        }
    }

    public function test_district_level_counts_and_coverage(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $school = $this->schoolIn($cluster);
        $inspector = $this->officer(UserLevel::Cluster, $cluster);
        $this->submitted($this->startInspection($inspector, $school));
        // an open draft in another school of the same cluster
        $other = School::where('cluster_id', $cluster->id)->where('id', '!=', $school->id)->orderBy('id')->first();
        $this->startInspection($this->officer(UserLevel::Cluster, $cluster), $other);

        $res = $this->actingAs($this->admin())->getJson(route('map.areas', 'district'))->assertOk();
        $north = collect($res->json('rows'))->firstWhere('id', $cluster->block->district_id);
        $schools = School::where('district_id', $cluster->block->district_id)->where('status', 'active')->count();

        $this->assertSame($schools, $north['schools']);
        $this->assertSame(1, $north['inspected']);
        $this->assertSame(1, $north['in_progress']);
        $this->assertSame($schools - 2, $north['pending']);
        $this->assertEquals(round(100 / $schools, 1), $north['coverage']);
        $this->assertSame(2, $north['officers']['cluster']);
        $this->assertSame(School::where('status', 'active')->count(), $res->json('totals.schools'));
        $this->assertNotNull($north['lat']); // centre from the schools when the district has no coordinates
    }

    public function test_school_markers_carry_status_and_last_inspection(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $school = $this->schoolIn($cluster);
        $inspector = $this->officer(UserLevel::Cluster, $cluster);
        $inspection = $this->startInspection($inspector, $school);
        $this->submitted($inspection);

        $rows = collect($this->actingAs($inspector)->getJson(route('map.schools', ['cluster' => $cluster->id]))->assertOk()->json('rows'));
        $row = $rows->firstWhere('id', $school->id);
        $this->assertSame('inspected', $row['status']);
        $this->assertSame($inspection->id, $row['last']['id']);
        $this->assertSame($inspector->name, $row['last']['officer']);
        $this->assertSame('pending', $rows->firstWhere('id', '!=', $school->id)['status']);

        // outside the period the school is pending again, but the last inspection stays
        $later = $this->actingAs($inspector)->getJson(route('map.schools', ['cluster' => $cluster->id, 'from' => now()->addMonth()->startOfMonth()->toDateString(), 'to' => now()->addMonth()->endOfMonth()->toDateString()]))->json('rows');
        $this->assertSame('pending', collect($later)->firstWhere('id', $school->id)['status']);
    }

    public function test_officers_cannot_open_areas_outside_their_jurisdiction(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $outside = $this->otherCluster($cluster);
        $blockOfficer = $this->officer(UserLevel::Block, $cluster);

        $this->actingAs($blockOfficer)->getJson(route('map.areas', ['level' => 'cluster', 'parent' => $cluster->block_id]))->assertOk();
        $this->actingAs($blockOfficer)->getJson(route('map.areas', ['level' => 'cluster', 'parent' => $outside->block_id]))->assertForbidden();
        $this->actingAs($blockOfficer)->getJson(route('map.areas', ['level' => 'block', 'parent' => $outside->block->district_id]))->assertForbidden();
        $districts = $this->actingAs($blockOfficer)->getJson(route('map.areas', 'district'))->assertOk()->json('rows');
        $this->assertSame([$cluster->block->district_id], array_column($districts, 'id'));
        $this->actingAs($blockOfficer)->getJson(route('map.schools', ['cluster' => $outside->id]))->assertForbidden();

        // the district view (breadcrumb) lists only the officer's own block
        $blocks = collect($this->actingAs($blockOfficer)->getJson(route('map.areas', ['level' => 'block', 'parent' => $cluster->block->district_id]))->assertOk()->json('rows'));
        $this->assertSame([$cluster->block_id], $blocks->pluck('id')->all());

        // search never returns schools outside the jurisdiction
        $found = collect($this->actingAs($blockOfficer)->getJson(route('map.schools', ['q' => 'Govt']))->assertOk()->json('rows'));
        $this->assertTrue($found->isNotEmpty());
        $this->assertTrue($found->every(fn ($s) => School::find($s['id'])->block_id === $cluster->block_id));

        // a cluster officer cannot open a neighbouring cluster of the same block
        $neighbour = Cluster::where('block_id', $cluster->block_id)->where('id', '!=', $cluster->id)->first();
        $this->actingAs($this->officer(UserLevel::Cluster, $cluster))->getJson(route('map.schools', ['cluster' => $neighbour->id]))->assertForbidden();
    }

    public function test_officer_filter_shows_only_that_officers_schools_and_must_be_in_area(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $a = $this->officer(UserLevel::Cluster, $cluster);
        $b = $this->officer(UserLevel::Cluster, $cluster);
        $schools = School::where('cluster_id', $cluster->id)->orderBy('id')->take(2)->get();
        $this->submitted($this->startInspection($a, $schools[0]));
        $this->submitted($this->startInspection($b, $schools[1]));

        $district = $this->officer(UserLevel::District, $cluster);
        $rows = $this->actingAs($district)->getJson(route('map.schools', ['cluster' => $cluster->id, 'officer' => $a->id]))->assertOk()->json('rows');
        $this->assertSame([$schools[0]->id], array_column($rows, 'id'));

        $stranger = $this->officer(UserLevel::Cluster, $this->otherCluster($cluster));
        $this->actingAs($district)->getJson(route('map.schools', ['cluster' => $cluster->id, 'officer' => $stranger->id]))->assertForbidden();
    }

    public function test_officer_counts_by_area_and_level(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $this->officer(UserLevel::Block, $cluster);
        $this->officer(UserLevel::Cluster, $cluster);
        $this->officer(UserLevel::Cluster, $cluster);
        $inactive = $this->officer(UserLevel::Cluster, $cluster);
        $inactive->update(['status' => 'inactive']);

        $rows = collect($this->actingAs($this->admin())->getJson(route('map.areas', ['level' => 'cluster', 'parent' => $cluster->block_id]))->assertOk()->json('rows'));
        $row = $rows->firstWhere('id', $cluster->id);
        $this->assertSame(2, $row['officers']['cluster']);
        $this->assertSame(2, $row['officers']['total']); // the block officer has no cluster

        $blocks = collect($this->actingAs($this->admin())->getJson(route('map.areas', ['level' => 'block', 'parent' => $cluster->block->district_id]))->json('rows'));
        $this->assertSame(['total' => 3, 'district' => 0, 'block' => 1, 'cluster' => 2], $blocks->firstWhere('id', $cluster->block_id)['officers']);
    }

    public function test_breadcrumb_and_area_coordinates(): void
    {
        $block = Block::orderBy('id')->first();
        $block->update(['latitude' => 26.5, 'longitude' => 92.1]);
        $res = $this->actingAs($this->admin())->getJson(route('map.areas', ['level' => 'block', 'parent' => $block->district_id]))->assertOk();
        $this->assertSame(['Assam', $block->district->label], array_column($res->json('breadcrumb'), 'name'));
        $row = collect($res->json('rows'))->firstWhere('id', $block->id);
        // 23-11-2026: the area point is the centre of its schools; the block's own point is its office (offices list)
        $avg = \App\Models\School::where('block_id', $block->id)->where('status', 'active')->whereNotNull('latitude')->avg('latitude');
        $this->assertEqualsWithDelta($avg !== null ? (float) $avg : 26.5, $row['lat'], 0.00001);
        $office = collect($res->json('offices'))->first(fn ($o) => $o['type'] === 'block' && $o['id'] === $block->id);
        $this->assertEquals([26.5, 92.1], [$office['lat'], $office['lng']]);
    }

    public function test_inputs_are_validated(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->getJson(route('map.areas', 'state'))->assertNotFound();
        $this->actingAs($admin)->getJson(route('map.schools'))->assertStatus(422);
        $this->actingAs($admin)->getJson(route('map.schools', ['q' => 'ab']))->assertStatus(422);
        $this->actingAs($admin)->getJson(route('map.areas', ['level' => 'district', 'from' => '2026-11-10', 'to' => '2026-11-01']))->assertStatus(422);
    }

    public function test_boundary_command_converts_kml_to_simplified_geojson(): void
    {
        $src = sys_get_temp_dir().'/si-kml-'.uniqid();
        $out = $src.'-out';
        mkdir($src.'/Assam Dist', 0777, true);
        $ring = implode(' ', array_map(fn ($i) => (91 + $i / 1000).','.(26 + ($i % 2) / 100000), range(0, 50))).' 91.05,26.1 91,26.1 91,26';
        $kml = fn ($extra) => '<?xml version="1.0"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document><Placemark>'.$extra
            .'<Polygon><outerBoundaryIs><LinearRing><coordinates>'.$ring.'</coordinates></LinearRing></outerBoundaryIs></Polygon></Placemark></Document></kml>';
        file_put_contents($src.'/Assam.kml', $kml('<name>Assam</name>'));
        file_put_contents($src.'/Assam Dist/kamrup metro.kml', $kml('<ExtendedData><SchemaData><SimpleData name="Con_Name">GAUHATI WEST</SimpleData></SchemaData></ExtendedData>'));

        $this->artisan('map:boundaries', ['source' => $src, '--out' => $out])->assertSuccessful();

        $districts = json_decode(file_get_contents($out.'/assam-districts.geojson'), true);
        $feature = $districts['features'][0];
        $this->assertSame('Kamrup Metro', $feature['properties']['name']);
        $this->assertSame('Gauhati West', $feature['properties']['constituency']);
        $coords = $feature['geometry']['coordinates'][0];
        $this->assertLessThan(54, count($coords));            // the straight edge is simplified
        $this->assertSame($coords[0], end($coords));          // the ring stays closed
        $this->assertFileExists($out.'/assam-state.geojson');
    }
}
