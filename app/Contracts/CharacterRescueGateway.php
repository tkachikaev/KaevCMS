<?php

namespace App\Contracts;

use App\Models\GameServer;
use App\Support\GameServerFeatures\CharacterRescueCapabilities;
use App\Support\GameServerFeatures\CharacterRescueWriteResult;
use Carbon\CarbonImmutable;

interface CharacterRescueGateway
{
    public function supports(GameServer $server): bool;

    public function capabilities(GameServer $server): CharacterRescueCapabilities;

    /** @param array{x:int,y:int,z:int} $target */
    public function rescue(
        GameServer $server,
        string $accountLogin,
        int $characterId,
        array $target,
        CarbonImmutable $offlineBefore,
    ): CharacterRescueWriteResult;
}
