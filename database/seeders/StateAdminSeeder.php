<?php

namespace Database\Seeders;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\State;
use App\Models\User;
use App\Models\UserJurisdiction;
use Illuminate\Database\Seeder;

/** The first State Administrator account, created at installation (PRD 3.1). */
class StateAdminSeeder extends Seeder
{
    public function run(): void
    {
        $state = State::first();

        $admin = User::firstOrCreate(
            ['mobile' => env('ADMIN_MOBILE', '9999999999')],
            [
                'name' => env('ADMIN_NAME', 'State Administrator'),
                'employee_id' => env('ADMIN_EMPLOYEE_ID', 'ADMIN001'),
                'password' => env('ADMIN_PASSWORD', 'Admin@12345'),
                'level' => UserLevel::StateAdmin,
                'status' => UserStatus::Active,
                'must_change_password' => true,
            ]
        );

        if ($state && ! $admin->currentJurisdiction()->exists()) {
            UserJurisdiction::create(['user_id' => $admin->id, 'state_id' => $state->id, 'start_date' => now()->toDateString()]);
        }
    }
}
