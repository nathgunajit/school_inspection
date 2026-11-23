<?php

namespace Database\Seeders;

use App\Models\AssemblyConstituency;
use App\Models\ParliamentaryConstituency;
use Illuminate\Database\Seeder;

/** AC / PC master for Assam (database/data/assam_constituencies.php). Safe to re-run; names edited later are kept. */
class ConstituencySeeder extends Seeder
{
    public function run(): void
    {
        $data = require database_path('data/assam_constituencies.php');
        $pcIds = [];
        foreach ($data['pc'] as [$code, $name]) {
            $pcIds[$code] = ParliamentaryConstituency::firstOrCreate(['code' => $code], ['name' => $name])->id;
        }
        foreach ($data['ac'] as [$code, $name, $pc]) {
            AssemblyConstituency::firstOrCreate(['code' => $code], ['name' => $name, 'pc_id' => $pcIds[$pc]]);
        }
    }
}
