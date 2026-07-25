<?php

namespace App\Contracts;

use App\Models\GameServer;
use Illuminate\Database\Connection;

interface GameServerDatabaseGateway
{
    /**
     * @template TResult
     *
     * @param  callable(Connection): TResult  $callback
     * @return TResult
     */
    public function run(GameServer $server, callable $callback): mixed;
}
