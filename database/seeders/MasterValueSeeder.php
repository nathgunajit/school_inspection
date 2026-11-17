<?php

namespace Database\Seeders;

use App\Models\MasterValue;
use Illuminate\Database\Seeder;

class MasterValueSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'designation' => [
                'director' => 'Director',
                'joint_director' => 'Joint Director',
                'deputy_director' => 'Deputy Director',
                'district_education_officer' => 'District Education Officer',
                'inspector_of_schools' => 'Inspector of Schools',
                'district_project_coordinator' => 'District Project Coordinator',
                'block_education_officer' => 'Block Education Officer',
                'block_resource_person' => 'Block Resource Person',
                'block_mission_coordinator' => 'Block Mission Coordinator',
                'cluster_resource_coordinator' => 'Cluster Resource Centre Coordinator',
                'other' => 'Other',
            ],
            'management' => [
                'dept_education' => 'Department of Education',
                'local_body' => 'Local Body',
                'govt_aided' => 'Government Aided',
                'tribal_welfare' => 'Tribal / Social Welfare Department',
                'central_govt' => 'Central Government',
                'other' => 'Other',
            ],
            'category' => [
                'primary' => 'Primary (Class 1–5)',
                'primary_upper' => 'Primary with Upper Primary (Class 1–8)',
                'upper_primary' => 'Upper Primary only (Class 6–8)',
                'secondary_full' => 'Primary to Secondary (Class 1–10)',
                'secondary' => 'Upper Primary with Secondary (Class 6–10)',
                'secondary_only' => 'Secondary only (Class 9–10)',
                'higher_secondary' => 'Higher Secondary',
            ],
            'photo_category' => [
                // v2 mandatory set (Modification V1, step 7)
                'school' => 'Photograph of School',
                'toilet_block' => 'Toilet Block',
                'ict_smart' => 'ICT Lab / Smart Classroom / Tele Education',
                'classroom_transaction' => 'Classroom Transaction',
                // v1 categories kept for history and optional extras
                'school_front' => 'School front with name board',
                'classroom' => 'Classroom in session',
                'toilet' => 'Toilets',
                'kitchen_mdm' => 'Kitchen or Mid-Day Meal',
                'drinking_water' => 'Drinking water',
                'playground' => 'Playground',
                'records' => 'Records',
                'cash_book' => 'Cash book page',
                'other' => 'Other',
            ],
            'fund_category' => [
                'composite_grant' => 'Composite School Grant',
                'mdm' => 'Mid-Day Meal (Cooking Cost)',
                'sports' => 'Sports & Physical Education',
                'library' => 'Library Grant',
                'other' => 'Other',
            ],
        ];

        foreach ($data as $type => $items) {
            $sort = 0;
            foreach ($items as $code => $label) {
                MasterValue::updateOrCreate(['type' => $type, 'code' => $code], ['label' => $label, 'sort' => $sort += 10]);
            }
        }
    }
}
