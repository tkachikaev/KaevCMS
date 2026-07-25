<?php

namespace Tests\Concerns;

use App\Models\GameServer;
use App\Models\LoginServer;
use App\Models\UserGameAccount;

trait InteractsWithServerFixtures
{
    protected function clearServerFixtures(): void
    {
        UserGameAccount::query()->delete();
        GameServer::query()->delete();
        LoginServer::query()->delete();
    }

    /**
     * @param  array<string,mixed>  $loginServerValues
     * @param  array<string,mixed>  $gameServerValues
     * @return array{LoginServer,GameServer}
     */
    protected function freshMobiusServerPair(
        array $loginServerValues = [],
        array $gameServerValues = [],
    ): array {
        $this->clearServerFixtures();

        $loginServer = LoginServer::factory()->create($loginServerValues);
        $gameServer = GameServer::factory()
            ->for($loginServer)
            ->create($gameServerValues);

        return [$loginServer, $gameServer];
    }
}
