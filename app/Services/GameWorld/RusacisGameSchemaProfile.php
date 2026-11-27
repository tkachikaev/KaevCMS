<?php

namespace App\Services\GameWorld;

final readonly class RusacisGameSchemaProfile
{
    public const NAME = 'rusacis_interlude';

    public function __construct(
        public bool $heroesAvailable,
        public bool $castlesAvailable,
        public bool $characterRescueAvailable,
    ) {}

    /** @return list<string> */
    public function capabilities(): array
    {
        $capabilities = ['level', 'pvp', 'pk', 'play_time'];

        if ($this->heroesAvailable) {
            $capabilities[] = 'heroes';
        }
        if ($this->castlesAvailable) {
            $capabilities[] = 'castles';
        }

        if ($this->characterRescueAvailable) {
            $capabilities[] = 'character_rescue';
        }

        return $capabilities;
    }

    /** @return array{heroes_available:bool,castles_available:bool,character_rescue_available:bool} */
    public function toArray(): array
    {
        return [
            'heroes_available' => $this->heroesAvailable,
            'castles_available' => $this->castlesAvailable,
            'character_rescue_available' => $this->characterRescueAvailable,
        ];
    }

    /** @param array{heroes_available:bool,castles_available:bool,character_rescue_available:bool} $values */
    public static function fromArray(array $values): self
    {
        return new self(
            heroesAvailable: $values['heroes_available'],
            castlesAvailable: $values['castles_available'],
            characterRescueAvailable: $values['character_rescue_available'],
        );
    }
}
