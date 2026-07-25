<?php

namespace App\Contracts;

use App\Models\GameServer;
use App\Support\Rewards\RewardQueueCapabilities;
use App\Support\Rewards\RewardQueuePayload;
use App\Support\Rewards\RewardQueueWriteResult;

interface GameRewardQueueGateway
{
    public function capabilities(GameServer $server): RewardQueueCapabilities;

    public function enqueue(GameServer $server, RewardQueuePayload $payload): RewardQueueWriteResult;
}
