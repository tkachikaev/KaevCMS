<?php

namespace App\Services\Updates;

final readonly class VdsUpdateAgentStatus
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $state,
        public string $message,
        public array $metadata = [],
    ) {}

    public function isReady(): bool
    {
        return $this->state === 'ready';
    }

    public function isInstalled(): bool
    {
        return in_array($this->state, ['ready', 'invalid'], true);
    }
}
