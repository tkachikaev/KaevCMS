<?php

namespace App\Support\Rewards;

use InvalidArgumentException;

final readonly class RewardGrantItem
{
    public function __construct(
        public int $itemId,
        public int $amount,
        public ?string $name = null,
    ) {
        if ($this->itemId <= 0) {
            throw new InvalidArgumentException('Reward item ID must be positive.');
        }

        if ($this->amount <= 0) {
            throw new InvalidArgumentException('Reward item amount must be positive.');
        }
    }
}
