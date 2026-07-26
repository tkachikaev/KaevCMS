<?php

namespace App\Services\GameWorld;

use App\Contracts\GameWorldDriver;
use App\Models\GameServer;
use App\Services\Servers\ServerDriverRegistry;
use RuntimeException;

final class GameWorldDriverResolver
{
    public function resolve(GameServer $server): GameWorldDriver
    {
        return match ((string) $server->driver) {
            ServerDriverRegistry::MOBIUS_DRIVER => app(MobiusGameWorldDriver::class),
            default => throw new RuntimeException('The selected GameServer driver does not provide game statistics.'),
        };
    }
}
