<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use Tests\TestCase;

/** 23-11-2026: District and Block offices (Location Master office location) on the Map View. */
class MapOfficesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['inspection.map.cache_seconds' => 0]);
    }

    private function types(array $offices): array
    {
        return collect($offices)->map(fn ($o) => $o['type'].':'.$o['id'])->sort()->values()->all();
    }

    public function test_offices_follow_the_level_in_view(): void
    {
        $cluster = Cluster::orderBy('id')->firstOrFail();
        $block = $cluster->block;
        $district = $block->district;
        $otherBlock = Block::where('district_id', '!=', $district->id)->firstOrFail();
        $district->update(['latitude' => 26.4, 'longitude' => 90.27, 'office_address' => 'DMC Office']);
        $block->update(['latitude' => 26.45, 'longitude' => 90.3]);
        $otherBlock->update(['latitude' => 26.0, 'longitude' => 91.0]);
        $admin = $this->admin();

        // state view: district offices only
        $state = $this->actingAs($admin)->getJson(route('map.areas', 'district'))->assertOk()->json('offices');
        $this->assertSame(['district:'.$district->id], $this->types($state));
        $this->assertSame('DMC Office', $state[0]['address']);
        $this->assertSame($district->label, $state[0]['label']);

        // inside the district: its office and its block offices
        $inDistrict = $this->getJson(route('map.areas', ['level' => 'block', 'parent' => $district->id]))->json('offices');
        $this->assertSame($this->types([['type' => 'block', 'id' => $block->id], ['type' => 'district', 'id' => $district->id]]), $this->types($inDistrict));

        // a cluster's schools: the block and district offices of that cluster
        $schools = $this->getJson(route('map.schools', ['cluster' => $cluster->id]))->assertOk();
        $this->assertSame($this->types([['type' => 'block', 'id' => $block->id], ['type' => 'district', 'id' => $district->id]]), $this->types($schools->json('offices')));
        $this->assertSame($block->id, $schools->json('rows.0.block_id'));

        // an office without coordinates is not listed
        $district->update(['latitude' => null, 'longitude' => null]);
        $this->assertSame(['block:'.$block->id], $this->types($this->getJson(route('map.areas', ['level' => 'cluster', 'parent' => $block->id]))->json('offices')));
    }

    public function test_officers_see_only_their_own_offices(): void
    {
        $cluster = Cluster::orderBy('id')->firstOrFail();
        $block = $cluster->block;
        $sibling = Block::where('district_id', $block->district_id)->whereKeyNot($block->id)->first();
        $block->update(['latitude' => 26.45, 'longitude' => 90.3]);
        $sibling?->update(['latitude' => 26.5, 'longitude' => 90.4]);
        $block->district->update(['latitude' => 26.4, 'longitude' => 90.27]);
        District::whereKeyNot($block->district_id)->update(['latitude' => 26.1, 'longitude' => 91.7]);

        $officer = $this->officer(UserLevel::Block, $cluster);
        $offices = $this->actingAs($officer)->getJson(route('map.areas', ['level' => 'block', 'parent' => $block->district_id]))->assertOk()->json('offices');
        $this->assertSame($this->types([['type' => 'block', 'id' => $block->id], ['type' => 'district', 'id' => $block->district_id]]), $this->types($offices),
            'a Block officer sees their own block office and the district office, not the other blocks');
        $this->assertSame(['district:'.$block->district_id], $this->types($this->getJson(route('map.areas', 'district'))->json('offices')));
    }

    /** 25-11-2026: a Cluster officer sees their District HQ and Block office on the map and on the Plan Inspections map. */
    public function test_cluster_officer_sees_district_hq_and_block_office_on_the_map_and_planner(): void
    {
        $cluster = Cluster::orderBy('id')->firstOrFail();
        $block = $cluster->block;
        $block->update(['latitude' => 26.45, 'longitude' => 90.3]);
        $block->district->update(['latitude' => 26.4, 'longitude' => 90.27]);
        District::whereKeyNot($block->district_id)->update(['latitude' => 26.1, 'longitude' => 91.7]);
        Block::whereKeyNot($block->id)->update(['latitude' => 26.2, 'longitude' => 91.2]);
        $own = $this->types([['type' => 'block', 'id' => $block->id], ['type' => 'district', 'id' => $block->district_id]]);

        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $offices = $this->actingAs($officer)->getJson(route('map.schools', ['cluster' => $cluster->id]))->assertOk()->json('offices');
        $this->assertSame($own, $this->types($offices));

        $cfg = $this->get(route('schedules.plan'))->assertOk()->viewData('config');
        $this->assertSame($own, $this->types($cfg['offices']), 'only the officer\'s own offices, not other districts or blocks');
    }

    public function test_office_address_is_saved_in_the_location_master(): void
    {
        $district = District::orderBy('id')->firstOrFail();
        $this->actingAs($this->admin())->put(route('admin.locations.update', ['districts', \App\Support\UrlId::encode(District::class, $district->id)]), [
            'code' => $district->code, 'name' => $district->name, 'state_id' => $district->state_id,
            'latitude' => '26.40', 'longitude' => '90.27', 'office_address' => 'Office of the DMC, Kokrajhar',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Office of the DMC, Kokrajhar', $district->fresh()->office_address);

        $this->get(route('admin.locations.index', 'districts'))->assertOk()->assertSee('Office address')->assertSee('Office of the DMC, Kokrajhar');
        $this->get(route('map.index'))->assertOk()->assertSee('id="fOffices"', false);
    }
}
