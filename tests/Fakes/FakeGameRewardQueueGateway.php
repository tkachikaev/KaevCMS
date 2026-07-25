<?php

namespace Tests\Fakes;

use App\Contracts\GameRewardQueueGateway;
use App\Models\GameServer;
use App\Support\Rewards\RewardQueueCapabilities;
use App\Support\Rewards\RewardQueuePayload;
use App\Support\Rewards\RewardQueueWriteResult;

class FakeGameRewardQueueGateway implements GameRewardQueueGateway
{
    public bool $supported = true;

    public string $unsupportedReason = 'reward_queue_not_installed';

    public string $outcome = RewardQueueWriteResult::STATUS_QUEUED;

    public ?string $failureCode = null;

    /** @var list<RewardQueuePayload> */
    public array $payloads = [];

    public function capabilities(GameServer $server): RewardQueueCapabilities
    {
        return $this->supported
            ? RewardQueueCapabilities::supported()
            : RewardQueueCapabilities::unsupported($this->unsupportedReason);
    }

    public function enqueue(GameServer $server, RewardQueuePayload $payload): RewardQueueWriteResult
    {
        $this->payloads[] = $payload;

        return match ($this->outcome) {
            RewardQueueWriteResult::STATUS_QUEUED => RewardQueueWriteResult::queued(),
            RewardQueueWriteResult::STATUS_FAILED => RewardQueueWriteResult::failed($this->failureCode ?? 'fake_queue_failed'),
            default => RewardQueueWriteResult::unknown($this->failureCode ?? 'fake_queue_unknown'),
        };
    }
}
