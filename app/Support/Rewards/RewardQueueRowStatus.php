<?php

namespace App\Support\Rewards;

enum RewardQueueRowStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Delivered = 'delivered';
    case Failed = 'failed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Delivered, self::Failed],
            self::Processing => [self::Delivered, self::Failed],
            self::Delivered, self::Failed => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }
}
