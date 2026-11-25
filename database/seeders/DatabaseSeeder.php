<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            MasterValueSeeder::class,
            ConstituencySeeder::class,
            DemoLocationSchoolSeeder::class,
            StateAdminSeeder::class,
            AssamHolidaySeeder::class,
        ]);

        // Demo officers for development and training only
        if (! app()->isProduction()) {
            $this->call([DemoUserSeeder::class, DemoNoticeSeeder::class]); // 25-11-2026: demo Notices & Orders
        }
    }
}
