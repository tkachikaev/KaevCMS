<?php

namespace App\Services\GameWorld;

use App\Contracts\GameWorldDriver;
use App\Models\GameServer;
use RuntimeException;

final class GameWorldDriverResolver
{
    public function resolve(GameServer $server): GameWorldDriver
    {
        return match ((string) $server->driver) {
            'l2j_mobius_ct0_interlude' => app(MobiusGameWorldDriver::class),
            default => throw new RuntimeException('The selected GameServer driver does not provide game statistics.'),
        };
    }
}
