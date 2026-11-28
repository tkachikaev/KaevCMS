<?php

namespace App\Services\GameWorld;

use App\Contracts\GameServerDatabaseGateway;
use App\Contracts\GameWorldDriver;
use App\Models\GameServer;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use RuntimeException;

final class GameWorldDriverResolver
{
    public function resolve(GameServer $server): GameWorldDriver
    {
        $snapshot = app(ConnectionProfileSnapshots::class)->get($server);
        $profile = $snapshot
            ?? (new BundledProfileCatalog)->all()['game:'.$server->driver]
            ?? throw new RuntimeException('The selected GameServer driver does not provide game statistics.');

        return new UniversalGameWorldDriver(
            $profile,
            app(GameServerDatabaseGateway::class),
            app(UniversalGameWorldReader::class),
            app(UniversalGameCharacterReader::class),
            $snapshot,
        );
    }
}
