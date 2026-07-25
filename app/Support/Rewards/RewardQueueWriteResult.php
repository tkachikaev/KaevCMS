<?php

namespace App\Support\Rewards;

final readonly class RewardQueueWriteResult
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    private function __construct(
        public string $status,
        public ?string $failureCode = null,
    ) {}

    public static function queued(): self
    {
        return new self(self::STATUS_QUEUED);
    }

    public static function failed(string $failureCode): self
    {
        return new self(self::STATUS_FAILED, $failureCode);
    }

    public static function unknown(string $failureCode = 'reward_queue_write_unknown'): self
    {
        return new self(self::STATUS_UNKNOWN, $failureCode);
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isUnknown(): bool
    {
        return $this->status === self::STATUS_UNKNOWN;
    }
}
