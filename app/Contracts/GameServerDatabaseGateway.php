<?php

namespace App\Contracts;

use App\Models\GameServer;
use Illuminate\Database\Connection;

interface GameServerDatabaseGateway
{
    /**
     * The optional second callback argument is the verified snapshot used for
     * the connection encoding (null for release-owned profiles). Callbacks
     * that do not use profile mappings may continue to accept only Connection.
     *
     * @template TResult
     *
     * @param  callable(Connection, array<string,mixed>|null=): TResult  $callback
     * @return TResult
     */
    public function run(GameServer $server, callable $callback): mixed;
}
