<?php

namespace Tests\Fakes;

use App\Contracts\GameServerDatabaseGateway;
use App\Models\GameServer;
use Illuminate\Database\Connection;
use RuntimeException;

final class FailingGameServerDatabaseGateway implements GameServerDatabaseGateway
{
    public int $calls = 0;

    /**
     * @template TResult
     *
     * @param  callable(Connection): TResult  $callback
     * @return TResult
     */
    public function run(GameServer $server, callable $callback): mixed
    {
        $this->calls++;

        throw new RuntimeException('The external game database is unavailable.');
    }
}
