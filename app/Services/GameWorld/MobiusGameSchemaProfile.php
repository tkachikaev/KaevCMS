<?php

namespace App\Services\GameWorld;

final readonly class MobiusGameSchemaProfile
{
    public const LEGACY = 'mobius_legacy';

    public const MODERN = 'mobius_modern';

    public function __construct(
        public string $name,
        public string $reputationColumn,
        public bool $heroesAvailable,
        public bool $castlesAvailable,
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

        return $capabilities;
    }

    /** @return array{name:string,reputation_column:string,heroes_available:bool,castles_available:bool} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'reputation_column' => $this->reputationColumn,
            'heroes_available' => $this->heroesAvailable,
            'castles_available' => $this->castlesAvailable,
        ];
    }

    /** @param array{name:string,reputation_column:string,heroes_available:bool,castles_available:bool} $values */
    public static function fromArray(array $values): self
    {
        return new self(
            name: $values['name'],
            reputationColumn: $values['reputation_column'],
            heroesAvailable: $values['heroes_available'],
            castlesAvailable: $values['castles_available'],
        );
    }
}
