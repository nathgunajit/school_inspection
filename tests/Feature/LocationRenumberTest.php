<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\ImportBatch;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Location IDs 1…n in code order (`locations:renumber`) and names shown with their code. */
class LocationRenumberTest extends TestCase
{
    /** school id => "district|block|cluster" codes */
    private function schoolCodes(): array
    {
        return DB::table('schools')->join('districts', 'districts.id', '=', 'schools.district_id')
            ->join('blocks', 'blocks.id', '=', 'schools.block_id')->join('clusters', 'clusters.id', '=', 'schools.cluster_id')
            ->orderBy('schools.id')->get(['schools.id', 'districts.code as d', 'blocks.code as b', 'clusters.code as c'])
            ->mapWithKeys(fn ($r) => [$r->id => "{$r->d}|{$r->b}|{$r->c}"])->all();
    }

    public function test_renumbers_locations_in_code_order_and_moves_every_link(): void
    {
        // a district added later with a code that sorts first, so it must become id 1
        $first = District::create(['state_id' => District::value('state_id'), 'code' => '1700', 'name' => 'ALPHA']);
        $block = Block::create(['district_id' => $first->id, 'code' => '170001', 'name' => 'ALPHA BLOCK']);
        $cluster = Cluster::create(['block_id' => $block->id, 'code' => '17000101', 'name' => 'ALPHA CLUSTER']);
        $school = School::create(['udise_code' => '17000100101', 'name' => 'Alpha LP School', 'state_id' => $first->state_id,
            'district_id' => $first->id, 'block_id' => $block->id, 'cluster_id' => $cluster->id, 'status' => 'active']);
        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $oldDistrict = $first->id;
        $batch = ImportBatch::create(['type' => 'school', 'file_name' => 'x.xlsx', 'uploaded_by' => $officer->id, 'summary' => [
            'districts' => [(string) $oldDistrict => ['name' => 'ALPHA', 'code' => '1700', 'added' => 1]],
            'blocks' => [(string) $block->id => ['name' => 'ALPHA BLOCK', 'district_id' => $oldDistrict, 'added' => 1]],
        ]]);
        $before = $this->schoolCodes();

        $this->artisan('locations:renumber', ['--force' => true])->assertSuccessful();

        foreach (['districts', 'blocks', 'clusters'] as $t) {
            $this->assertSame(range(1, DB::table($t)->count()), DB::table($t)->orderBy('code')->pluck('id')->map(fn ($i) => (int) $i)->all(), $t);
        }
        $this->assertSame(1, (int) District::where('code', '1700')->value('id'));
        $this->assertSame($before, $this->schoolCodes(), 'every school keeps its district, block and cluster');
        $this->assertSame('17000101', Cluster::find($officer->fresh()->currentJurisdiction->cluster_id)->code);
        $this->assertSame('1700', $school->fresh()->district->code);
        $summary = $batch->fresh()->summary;
        $this->assertSame(['1'], array_map('strval', array_keys($summary['districts'])));
        $this->assertSame(1, $summary['blocks'][array_key_first($summary['blocks'])]['district_id']);
        $this->assertSame((string) Block::where('code', '170001')->value('id'), (string) array_key_first($summary['blocks']));

        // a second run changes nothing
        $this->artisan('locations:renumber', ['--force' => true])->expectsOutputToContain('Renumbered 0 district(s), 0 block(s) and 0 cluster(s)')->assertSuccessful();
    }

    public function test_location_names_carry_their_code(): void
    {
        $d = District::orderBy('id')->first();
        $this->assertSame("{$d->name} ({$d->code})", $d->label);
        $cluster = Cluster::orderBy('id')->first();

        $this->actingAs($this->admin())->get(route('admin.schools.index', ['district_id' => $cluster->block->district_id, 'block_id' => $cluster->block_id, 'cluster_id' => $cluster->id]))->assertOk()
            ->assertSee(e($cluster->label), false)->assertSee(e($cluster->block->label), false);
        $school = $this->schoolIn($cluster);
        $this->get(route('schools.show', $school))->assertOk()->assertSee(e($cluster->block->district->label), false);
    }
}
