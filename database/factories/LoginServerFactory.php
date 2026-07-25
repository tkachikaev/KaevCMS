<?php

namespace Database\Factories;

use App\Models\LoginServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LoginServer> */
class LoginServerFactory extends Factory
{
    protected $model = LoginServer::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Main LoginServer',
            'driver' => 'l2j_mobius',
            'database_host' => '127.0.0.1',
            'database_port' => 3306,
            'database_name' => 'l2j',
            'database_username' => 'cms',
            'database_password' => 'secret',
            'database_charset' => 'utf8',
            'service_host' => 'login.example.test',
            'service_port' => 2106,
            'database_status' => 'unknown',
            'database_error' => null,
            'database_checked_at' => null,
            'monitor_status' => 'unknown',
            'monitor_failures' => 0,
            'monitor_checked_at' => null,
            'monitor_last_online_at' => null,
        ];
    }

    public function legacy(): static
    {
        return $this->state(fn (): array => ['driver' => 'l2j_mobius_legacy']);
    }

    public function online(): static
    {
        return $this->state(fn (): array => [
            'database_status' => 'configured',
            'database_error' => null,
            'database_checked_at' => now(),
            'monitor_status' => 'online',
            'monitor_failures' => 0,
            'monitor_checked_at' => now(),
            'monitor_last_online_at' => now(),
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn (): array => [
            'database_status' => 'configured',
            'database_error' => null,
            'database_checked_at' => now(),
            'monitor_status' => 'offline',
            'monitor_failures' => 3,
            'monitor_checked_at' => now(),
        ]);
    }

    public function stale(string $status = 'online'): static
    {
        return $this->state(fn (): array => [
            'database_status' => 'configured',
            'database_error' => null,
            'database_checked_at' => now()->subHours(4),
            'monitor_status' => $status,
            'monitor_failures' => $status === 'offline' ? 3 : 0,
            'monitor_checked_at' => now()->subHours(4),
            'monitor_last_online_at' => $status === 'online' ? now()->subHours(4) : null,
        ]);
    }
}
