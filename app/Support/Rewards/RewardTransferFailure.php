<?php

namespace App\Support\Rewards;

enum RewardTransferFailure: string
{
    case RewardQueueNotInstalled = 'reward_queue_not_installed';
    case RewardQueueSchemaInvalid = 'reward_queue_schema_invalid';
    case RewardQueueUnavailable = 'reward_queue_unavailable';
    case CharacterNotOwned = 'character_not_owned';
    case InvalidSelection = 'invalid_selection';
    case ItemsUnavailable = 'items_unavailable';
    case RewardQueueWriteFailed = 'reward_queue_write_failed';

    public static function fromQueueReason(?string $reasonCode): self
    {
        return match ($reasonCode) {
            'reward_queue_not_installed' => self::RewardQueueNotInstalled,
            'reward_queue_schema_invalid' => self::RewardQueueSchemaInvalid,
            default => self::RewardQueueUnavailable,
        };
    }

    public function translationKey(): string
    {
        return 'rewards.transfer.'.$this->value;
    }
}
