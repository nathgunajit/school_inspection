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
                'district_programmer' => 'District Programmer', // 27-11-2026 (District level)
                'block_education_officer' => 'Block Education Officer',
                'block_resource_person' => 'Block Resource Person',
                'block_mission_coordinator' => 'Block Mission Coordinator',
                'block_mis' => 'Block MIS', // 27-11-2026 (Block level)
                'cluster_resource_coordinator' => 'Cluster Resource Centre Coordinator',
                'other' => 'Other',
            ],
            'management' => [
                'dept_education' => 'Department of Education',
                'local_body' => 'Local Body',
                'govt_aided' => 'Government Aided',
                'tribal_welfare' => 'Tribal / Social Welfare Department',
                'central_govt' => 'Central Government',
                // UDISE+ 2025-26 management codes 10 and 17 (School Master upload)
                'kgbv' => 'KGBV / Residential School',
                'self_based' => 'Self Based School',
                'other' => 'Other',
            ],
            'category' => [
                'primary' => 'Primary (Class 1–5)',
                'primary_upper' => 'Primary with Upper Primary (Class 1–8)',
                'upper_primary' => 'Upper Primary only (Class 6–8)',
                'secondary_full' => 'Primary to Secondary (Class 1–10)',
                'secondary' => 'Upper Primary with Secondary (Class 6–10)',
                'secondary_only' => 'Secondary only (Class 9–10)',
                // UDISE+ 2025-26 category codes 3, 5 and 10 (School Master upload)
                'pr_to_hsec' => 'Primary to Higher Secondary (Class 1–12)',
                'upr_to_hsec' => 'Upper Primary to Higher Secondary (Class 6–12)',
                'sec_hsec' => 'Secondary with Higher Secondary (Class 9–12)',
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
            // Subjects taught in Assam Government schools (Class I–XII); suggestions for Classroom Performance (Modification V2 item 10).
            // Officers may still type a subject that is not in this list.
            'subject' => [
                'assamese' => 'Assamese', 'english' => 'English', 'hindi' => 'Hindi', 'bengali' => 'Bengali', 'bodo' => 'Bodo',
                'sanskrit' => 'Sanskrit', 'arabic' => 'Arabic', 'urdu' => 'Urdu', 'nepali' => 'Nepali', 'manipuri' => 'Manipuri',
                'mising' => 'Mising', 'karbi' => 'Karbi', 'garo' => 'Garo', 'mil' => 'Modern Indian Language (MIL)',
                'mathematics' => 'Mathematics', 'general_mathematics' => 'General Mathematics', 'advanced_mathematics' => 'Advanced Mathematics',
                'evs' => 'Environmental Studies (EVS)', 'general_science' => 'General Science', 'science' => 'Science', 'social_science' => 'Social Science',
                'physics' => 'Physics', 'chemistry' => 'Chemistry', 'biology' => 'Biology', 'botany' => 'Botany', 'zoology' => 'Zoology',
                'history' => 'History', 'geography' => 'Geography', 'political_science' => 'Political Science', 'economics' => 'Economics',
                'logic_philosophy' => 'Logic & Philosophy', 'education' => 'Education', 'sociology' => 'Sociology', 'psychology' => 'Psychology',
                'statistics' => 'Statistics', 'accountancy' => 'Accountancy', 'business_studies' => 'Business Studies', 'commerce' => 'Commerce',
                'computer_science' => 'Computer Science', 'information_technology' => 'Information Technology', 'computer_education' => 'Computer Education',
                'health_physical_education' => 'Health & Physical Education', 'art_education' => 'Art Education', 'work_education' => 'Work Education',
                'music' => 'Music', 'fine_arts' => 'Fine Arts', 'home_science' => 'Home Science', 'moral_education' => 'Moral Education',
                'yoga' => 'Yoga', 'vocational_education' => 'Vocational Education', 'general_knowledge' => 'General Knowledge', 'drawing' => 'Drawing',
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
