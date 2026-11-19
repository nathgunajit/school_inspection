<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Cluster;
use App\Models\District;
use App\Services\DistrictBoundaryService;
use App\Support\UrlId;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** V6 Module 7: district outlines dissolved from the district KML, linked to the District Master and drawn on the maps. */
class DistrictBoundaryTest extends TestCase
{
    private string $src;

    private string $rel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->src = sys_get_temp_dir().'/si-kml-'.uniqid();
        $this->rel = 'geo-test-'.uniqid();
        mkdir($this->src.'/Assam Dist', 0777, true);

        // Northpur: two constituencies side by side (shared edge at lng 91.8) around the seeded North District schools;
        // Southpur: one polygon to the north of it, around the South District schools.
        $this->kml('Northpur', [[[91.6, 26.0], [91.8, 26.0], [91.8, 26.265], [91.6, 26.265]], [[91.8, 26.0], [92.0, 26.0], [92.0, 26.265], [91.8, 26.265]]]);
        $this->kml('Southpur', [[[91.6, 26.265], [92.0, 26.265], [92.0, 26.5], [91.6, 26.5]]]);
        file_put_contents($this->src.'/Assam.kml', '<?xml version="1.0"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document></Document></kml>');

        $this->artisan('map:boundaries', ['source' => $this->src, '--out' => public_path($this->rel)])->assertSuccessful();
        config(['inspection.map.geojson.district_dir' => $this->rel.'/districts']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path($this->rel));
        File::deleteDirectory($this->src);
        parent::tearDown();
    }

    /** A district KML whose placemarks are the given rings (closed here). */
    private function kml(string $name, array $rings): void
    {
        $marks = '';
        foreach ($rings as $r) {
            $r[] = $r[0];
            $coords = implode(' ', array_map(fn ($p) => $p[0].','.$p[1], $r));
            $marks .= '<Placemark><Polygon><outerBoundaryIs><LinearRing><coordinates>'.$coords.'</coordinates></LinearRing></outerBoundaryIs></Polygon></Placemark>';
        }
        file_put_contents($this->src."/Assam Dist/$name.kml", '<?xml version="1.0"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>'.$marks.'</Document></kml>');
    }

    public function test_constituencies_are_dissolved_into_one_outline_with_an_index(): void
    {
        $index = json_decode(file_get_contents(public_path($this->rel.'/districts/index.json')), true);
        $this->assertSame(['Northpur', 'Southpur'], array_column($index, 'name'));
        $this->assertEquals([91.6, 26.0, 92.0, 26.265], $index[0]['bbox']);

        $geo = json_decode(file_get_contents(public_path($this->rel.'/districts/northpur.geojson')), true);
        $polys = $geo['features'][0]['geometry']['coordinates'];
        $this->assertSame('MultiPolygon', $geo['features'][0]['geometry']['type']);
        $this->assertCount(1, $polys, 'the two constituencies become one shape');
        $ring = $polys[0][0];
        $this->assertCount(5, $ring, 'the shared edge is gone and the collinear points are simplified: 4 corners, closed');
        $this->assertSame($ring[0], end($ring));
        $this->assertNotContains('91.8,26', array_map(fn ($p) => implode(',', $p), $ring), 'no vertex left on the removed shared edge');
    }

    public function test_service_matches_by_boundary_name_or_name_and_locates_points(): void
    {
        $svc = app(DistrictBoundaryService::class);
        $north = District::where('code', '1801')->first();

        $this->assertSame('Northpur', $svc->locate(26.1, 91.9));
        $this->assertSame('Southpur', $svc->locate(26.4, 91.7));
        $this->assertNull($svc->locate(27.0, 91.7));
        $this->assertNull($svc->nameFor($north));
        $this->assertSame('Northpur', $svc->suggest($north), 'from the average position of its schools');

        $north->update(['boundary_name' => 'Northpur']);
        $this->assertSame('Northpur', $svc->nameFor($north));
        $this->assertStringEndsWith('/'.$this->rel.'/districts/northpur.geojson', $svc->urlFor($north));

        $this->assertSame('Southpur', $svc->nameFor(new District(['name' => 'SOUTH-PUR'])), 'by name, case and punctuation ignored');
    }

    public function test_check_and_assign_link_unmatched_districts(): void
    {
        $this->artisan('map:boundaries', ['--check' => true])
            ->expectsOutputToContain('suggested: Northpur')->expectsOutputToContain('2 district(s) without an outline')->assertSuccessful();
        $this->assertNull(District::where('code', '1801')->value('boundary_name'), '--check changes nothing');

        $this->artisan('map:boundaries', ['--assign' => true])->expectsOutputToContain('Every district has an outline.')->assertSuccessful();
        $this->assertSame(['1801' => 'Northpur', '1802' => 'Southpur'], District::orderBy('code')->pluck('boundary_name', 'code')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'district_boundary_assigned', 'auditable_type' => 'District']);
    }

    public function test_map_json_carries_the_district_outline_and_boundary(): void
    {
        $this->artisan('map:boundaries', ['--assign' => true]);
        $cluster = Cluster::orderBy('id')->first();
        $district = $cluster->block->district;
        $officer = $this->officer(UserLevel::Cluster, $cluster);

        $res = $this->actingAs($officer)->getJson(route('map.schools', ['cluster' => $cluster->id]))->assertOk();
        $this->assertStringEndsWith('/districts/northpur.geojson', $res->json('outline'));

        $admin = $this->admin();
        $this->assertNull($this->actingAs($admin)->getJson(route('map.areas', ['level' => 'district']))->json('outline'), 'no outline at state level');
        $rows = collect($this->actingAs($admin)->getJson(route('map.areas', ['level' => 'district']))->json('rows'));
        $this->assertSame('Northpur', $rows->firstWhere('id', $district->id)['boundary']);
        $this->assertStringEndsWith('/northpur.geojson', $this->actingAs($admin)->getJson(route('map.areas', ['level' => 'block', 'parent' => $district->id]))->json('outline'));

        $this->actingAs($admin)->get(route('schedules.plan', ['officer' => $officer->id]))->assertOk()->assertSee('northpur.geojson', false);
    }

    public function test_location_master_sets_the_boundary_from_the_list_only(): void
    {
        $district = District::where('code', '1802')->first();
        $url = route('admin.locations.update', ['districts', UrlId::encode(District::class, $district->id)]);
        $admin = $this->admin();
        $base = ['code' => $district->code, 'name' => $district->name, 'state_id' => $district->state_id];

        $this->actingAs($admin)->get(route('admin.locations.index', 'districts'))->assertOk()->assertSee('Map boundary')->assertSee('Southpur');
        $this->actingAs($admin)->put($url, $base + ['boundary_name' => 'Nowhere'])->assertSessionHasErrors('boundary_name');
        $this->actingAs($admin)->put($url, $base + ['boundary_name' => 'Southpur'])->assertSessionHasNoErrors();
        $this->assertSame('Southpur', $district->fresh()->boundary_name);
        $this->actingAs($admin)->put($url, $base + ['boundary_name' => ''])->assertSessionHasNoErrors();
        $this->assertNull($district->fresh()->boundary_name);
    }
}
