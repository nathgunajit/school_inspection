<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Cluster;
use Tests\TestCase;

/** V6 Module 8: my location, distance from the school and the road route (map page and school page). */
class SchoolDirectionsTest extends TestCase
{
    public function test_school_page_has_the_location_and_route_card_when_the_school_has_coordinates(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $school = $this->schoolIn($cluster);
        $school->update(['latitude' => 26.1445, 'longitude' => 91.7362]);

        $res = $this->actingAs($officer)->get(route('schools.show', $school))->assertOk()
            ->assertSee('Location &amp; route', false)->assertSee('id="schoolMap"', false)->assertSee('school-map', false);
        $config = $res->viewData('mapConfig');
        $this->assertSame(['lat' => 26.1445, 'lng' => 91.7362, 'name' => $school->name], $config['school']);
        $this->assertSame(config('inspection.map.routing'), $config['routing']);
        $this->assertSame('https://router.project-osrm.org', $config['routing']['url']);

        $school->update(['latitude' => null, 'longitude' => null]);
        $this->actingAs($officer)->get(route('schools.show', $school))->assertOk()->assertDontSee('id="schoolMap"', false);
        $this->assertNull($this->actingAs($officer)->get(route('schools.show', $school))->viewData('mapConfig'));
    }

    public function test_map_page_passes_the_routing_settings(): void
    {
        config(['inspection.map.routing.url' => 'https://osrm.example.org', 'inspection.map.routing.enabled' => false]);
        $config = $this->actingAs($this->officer(UserLevel::District))->get(route('map.index'))->assertOk()->viewData('config');

        $this->assertSame(['enabled' => false, 'url' => 'https://osrm.example.org', 'profile' => 'driving', 'timeout' => 8000], $config['routing']);
    }
}
