<?php

namespace App\Support\GameServerFeatures;

final readonly class CharacterRescueCapabilities
{
    /** @param list<string> $missingColumns */
    public function __construct(
        public bool $supported,
        public array $missingColumns = [],
    ) {}
}
