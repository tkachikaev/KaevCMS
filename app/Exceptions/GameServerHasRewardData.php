<?php

namespace App\Exceptions;

use RuntimeException;

final class GameServerHasRewardData extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('GameServer deletion is blocked because reward data exists.');
    }
}
