<?php

namespace App\Support\GameServerFeatures;

final readonly class CharacterRescueWriteResult
{
    public const SUCCESS = 'success';

    public const UNSUPPORTED = 'unsupported';

    public const NOT_FOUND = 'not_found';

    public const ONLINE = 'online';

    public const OFFLINE_DELAY = 'offline_delay';

    public const STATE_CHANGED = 'state_changed';

    public function __construct(
        public string $status,
        public ?string $characterName = null,
        public ?int $oldX = null,
        public ?int $oldY = null,
        public ?int $oldZ = null,
    ) {}

    public function successful(): bool
    {
        return $this->status === self::SUCCESS;
    }
}
