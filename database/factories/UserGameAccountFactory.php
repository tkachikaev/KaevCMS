<?php

namespace Database\Factories;

use App\Models\GameServer;
use App\Models\LoginServer;
use App\Models\User;
use App\Models\UserGameAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<UserGameAccount> */
class UserGameAccountFactory extends Factory
{
    protected $model = UserGameAccount::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'login_server_id' => LoginServer::factory(),
            'registration_game_server_id' => null,
            'game_login' => fake()->unique()->numerify('Player######'),
            'normalized_login' => fn (array $attributes): string => Str::lower((string) $attributes['game_login']),
            'created_via_cms' => true,
        ];
    }

    public function registeredOn(GameServer $server): static
    {
        return $this->state(fn (): array => [
            'login_server_id' => $server->login_server_id,
            'registration_game_server_id' => $server->id,
        ]);
    }

    public function orphaned(LoginServer $loginServer): static
    {
        return $this->state(fn (): array => [
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => null,
        ]);
    }
}
