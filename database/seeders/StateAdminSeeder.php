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

        // 25-11-2026: re-running keeps the existing State Administrator even when their mobile was changed later
        $admin = User::where('mobile', env('ADMIN_MOBILE', '9999999999'))->first()
            ?? User::where('level', UserLevel::StateAdmin->value)->orderBy('id')->first()
            ?? User::create(['mobile' => env('ADMIN_MOBILE', '9999999999')] +
            [
                'name' => env('ADMIN_NAME', 'State Administrator'),
                'employee_id' => env('ADMIN_EMPLOYEE_ID', 'ADMIN001'),
                'password' => env('ADMIN_PASSWORD', 'Admin@12345'),
                'gender' => env('ADMIN_GENDER', 'male'), // 25-11-2026: shows the male default photo until one is uploaded
                'level' => UserLevel::StateAdmin,
                'status' => UserStatus::Active,
                'must_change_password' => true,
            ]
        );

        if ($admin->gender === null) { // installs from before 25-11-2026
            $admin->forceFill(['gender' => env('ADMIN_GENDER', 'male')])->save();
        }

        if ($state && ! $admin->currentJurisdiction()->exists()) {
            UserJurisdiction::create(['user_id' => $admin->id, 'state_id' => $state->id, 'start_date' => now()->toDateString()]);
        }
    }
}
