<?php

namespace App\Support\GameServerFeatures;

use Carbon\CarbonImmutable;

final readonly class CharacterRescueOutcome
{
    public function __construct(
        public bool $successful,
        public string $code,
        public ?string $characterName = null,
        public ?string $locationName = null,
        public ?CarbonImmutable $retryAt = null,
    ) {}
}
