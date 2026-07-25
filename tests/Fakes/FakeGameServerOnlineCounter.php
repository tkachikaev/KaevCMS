<?php

namespace Tests\Fakes;

use App\Contracts\GameServerOnlineCounter;
use App\Models\GameServer;
use RuntimeException;

final class FakeGameServerOnlineCounter implements GameServerOnlineCounter
{
    /** @var array<int,int> */
    public array $counts = [];

    /** @var list<int> */
    public array $failures = [];

    public function count(GameServer $gameServer): int
    {
        if (in_array($gameServer->id, $this->failures, true)) {
            throw new RuntimeException('Fake online counter failure.');
        }

        return $this->counts[$gameServer->id] ?? 0;
    }
}
