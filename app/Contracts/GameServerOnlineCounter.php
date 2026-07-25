<?php

namespace App\Contracts;

use App\Models\GameServer;

interface GameServerOnlineCounter
{
    public function count(GameServer $gameServer): int;
}
