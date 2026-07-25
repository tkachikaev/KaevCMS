<?php

namespace Tests\Fakes;

use App\Contracts\GameServerDatabaseGateway;
use App\Models\GameServer;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

final class FakeGameServerDatabaseGateway implements GameServerDatabaseGateway
{
    public int $calls = 0;

    public function __construct(private readonly string $connectionName) {}

    /**
     * @template TResult
     *
     * @param  callable(Connection): TResult  $callback
     * @return TResult
     */
    public function run(GameServer $server, callable $callback): mixed
    {
        $this->calls++;

        return $callback(DB::connection($this->connectionName));
    }
}
