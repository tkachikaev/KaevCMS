<?php

namespace App\Support\Rewards;

final readonly class RewardQueueCapabilities
{
    public function __construct(
        public bool $supported,
        public ?string $reasonCode = null,
    ) {}

    public static function supported(): self
    {
        return new self(true);
    }

    public static function unsupported(string $reasonCode): self
    {
        return new self(false, $reasonCode);
    }
}
