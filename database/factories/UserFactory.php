<?php

namespace Database\Factories;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'employee_id' => 'EMP'.Str::upper(Str::random(8)),
            'mobile' => (string) fake()->unique()->numberBetween(6000000000, 9999999998),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('Secret@123'),
            'level' => UserLevel::Cluster,
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    public function level(UserLevel $level): static
    {
        return $this->state(fn () => ['level' => $level]);
    }

    public function status(UserStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
