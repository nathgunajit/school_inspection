<?php

namespace Database\Seeders;

use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\MasterValue;
use App\Models\School;
use App\Models\State;
use Illuminate\Database\Seeder;

/**
 * Demo Location and School Master for development and testing.
 * Replace with the Department's Excel upload in production (PRD 7).
 */
class DemoLocationSchoolSeeder extends Seeder
{
    public function run(): void
    {
        // Modification V13: never overwrite a real Location Master (the demo has only two districts)
        if (District::count() > 2) {
            $this->command?->warn('DemoLocationSchoolSeeder skipped: a Location Master is already loaded.');

            return;
        }

        $state = State::updateOrCreate(['code' => '18'], ['name' => 'Demo State']);

        $districts = [
            '1801' => ['North District', ['180101' => 'Riverside Block', '180102' => 'Hillview Block']],
            '1802' => ['South District', ['180201' => 'Lakeside Block', '180202' => 'Greenfield Block']],
        ];
        $schoolNames = ['Govt. Primary School', 'Govt. Upper Primary School', 'Model Secondary School', 'Govt. LP School'];
        $mgmt = MasterValue::idFor('management', 'dept_education');
        $cats = MasterValue::where('type', 'category')->pluck('id')->values();
        $baseLat = 26.10;
        $baseLng = 91.70;
        $n = 0;

        foreach ($districts as $dCode => [$dName, $blocks]) {
            $district = District::updateOrCreate(['code' => $dCode], ['state_id' => $state->id, 'name' => $dName]);
            foreach ($blocks as $bCode => $bName) {
                $block = Block::updateOrCreate(['code' => $bCode], ['district_id' => $district->id, 'name' => $bName]);
                foreach ([1, 2] as $c) {
                    $cCode = $bCode.'0'.$c;
                    $cluster = Cluster::updateOrCreate(['code' => $cCode], ['block_id' => $block->id, 'name' => explode(' ', $bName)[0].' Cluster '.$c]);
                    foreach ([1, 2, 3] as $s) {
                        $n++;
                        $udise = $cCode.str_pad((string) $s, 3, '0', STR_PAD_LEFT); // 8 + 3 = 11 digits
                        School::updateOrCreate(['udise_code' => $udise], [
                            'name' => $schoolNames[($n - 1) % 4].' No. '.$n,
                            'state_id' => $state->id,
                            'district_id' => $district->id,
                            'block_id' => $block->id,
                            'cluster_id' => $cluster->id,
                            'management_id' => $mgmt,
                            'category_id' => $cats[$n % $cats->count()],
                            'address' => 'Village '.$n.', '.$bName.', '.$dName,
                            'latitude' => $s === 3 ? null : round($baseLat + $n * 0.013, 7),
                            'longitude' => $s === 3 ? null : round($baseLng + $n * 0.011, 7),
                            'status' => 'active',
                        ]);
                    }
                }
            }
        }
    }
}
