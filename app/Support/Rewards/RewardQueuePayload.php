<?php

namespace App\Support\Rewards;

/**
 * @phpstan-type QueueItem array{item_id:int,amount:int}
 */
final readonly class RewardQueuePayload
{
    /**
     * @param  list<QueueItem>  $items
     */
    public function __construct(
        public string $requestUuid,
        public int $gameServerId,
        public int $cmsUserId,
        public string $accountName,
        public int $characterId,
        public string $characterName,
        public array $items,
        public string $source = 'web_inventory',
    ) {}
}
