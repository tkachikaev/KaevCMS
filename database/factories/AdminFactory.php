<?php

namespace Database\Factories;

use App\Auth\AdminRole;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<Admin> */
class AdminFactory extends Factory
{
    protected $model = Admin::class;

    private static ?string $password = null;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => self::$password ??= Hash::make('CorrectPassword123'),
            'is_active' => true,
            'role' => AdminRole::Owner,
            'locale' => 'ru',
        ];
    }

    public function administrator(): static
    {
        return $this->state(fn (): array => ['role' => AdminRole::Administrator]);
    }

    public function editor(): static
    {
        return $this->state(fn (): array => ['role' => AdminRole::Editor]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
