<?php

namespace App\Services\GameWorld;

use App\Contracts\GameServerDatabaseGateway;
use App\Contracts\GameWorldDriver;
use App\Models\GameServer;
use App\Services\Servers\ServerDriverRegistry;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use RuntimeException;

final class GameWorldDriverResolver
{
    public function resolve(GameServer $server): GameWorldDriver
    {
        $profile = app(ConnectionProfileSnapshots::class)->get($server);
        if ($profile !== null) {
            return new UniversalGameWorldDriver($profile,
                app(GameServerDatabaseGateway::class), app(UniversalGameWorldReader::class),
                app(UniversalGameCharacterReader::class));
        }

        return match ((string) $server->driver) {
            ServerDriverRegistry::MOBIUS_DRIVER => app(MobiusGameWorldDriver::class),
            ServerDriverRegistry::RUSACIS_DRIVER => app(RusacisGameWorldDriver::class),
            default => throw new RuntimeException('The selected GameServer driver does not provide game statistics.'),
        };
    }
}
