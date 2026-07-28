<?php

namespace App\Services\GameWorld;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use RuntimeException;

final class MobiusGameSchemaInspector
{
    private const CHARACTER_COLUMNS = [
        'account_name',
        'charId',
        'char_name',
        'level',
        'exp',
        'classid',
        'race',
        'sex',
        'title',
        'online',
        'onlinetime',
        'accesslevel',
        'deletetime',
        'pvpkills',
        'pkkills',
        'nobless',
        'clanid',
        'lastAccess',
    ];

    private const REPUTATION_COLUMNS = ['karma', 'reputation'];

    private const CHARACTER_RESCUE_COLUMNS = ['x', 'y', 'z'];

    private const CLAN_COLUMNS = ['clan_id', 'clan_name', 'clan_level', 'reputation_score', 'hasCastle', 'leader_id'];

    private const HERO_COLUMNS = ['charId', 'class_id', 'count', 'played', 'claimed'];

    private const CASTLE_COLUMNS = ['id', 'name'];

    /**
     * @return list<array{table:string,columns:list<string>,any_columns?:list<string>,optional_columns?:list<string>,required:bool}>
     */
    public static function requirements(): array
    {
        return [
            [
                'table' => 'characters',
                'columns' => self::CHARACTER_COLUMNS,
                'any_columns' => self::REPUTATION_COLUMNS,
                'optional_columns' => self::CHARACTER_RESCUE_COLUMNS,
                'required' => true,
            ],
            [
                'table' => 'clan_data',
                'columns' => self::CLAN_COLUMNS,
                'required' => true,
            ],
            [
                'table' => 'heroes',
                'columns' => self::HERO_COLUMNS,
                'required' => false,
            ],
            [
                'table' => 'castle',
                'columns' => self::CASTLE_COLUMNS,
                'required' => false,
            ],
        ];
    }

    public function inspect(Connection $connection, ?string $chronicle = null): MobiusGameSchemaProfile
    {
        $schema = $connection->getSchemaBuilder();
        $this->assertRequiredTable($schema, 'characters', self::CHARACTER_COLUMNS);
        $this->assertRequiredTable($schema, 'clan_data', self::CLAN_COLUMNS);

        $hasKarma = $schema->hasColumn('characters', 'karma');
        $hasReputation = $schema->hasColumn('characters', 'reputation');

        if (! $hasKarma && ! $hasReputation) {
            throw new RuntimeException('The Mobius characters table must contain either karma or reputation.');
        }

        $profile = $this->profileForColumns(
            hasKarma: $hasKarma,
            hasReputation: $hasReputation,
            heroesAvailable: $this->tableHasColumns($schema, 'heroes', self::HERO_COLUMNS),
            castlesAvailable: $this->tableHasColumns($schema, 'castle', self::CASTLE_COLUMNS),
            characterRescueAvailable: $this->tableHasColumns($schema, 'characters', [
                ...self::CHARACTER_COLUMNS,
                ...self::CHARACTER_RESCUE_COLUMNS,
            ]),
            chronicle: $chronicle,
        );

        if (! $profile instanceof MobiusGameSchemaProfile) {
            throw new RuntimeException('The Mobius characters table must contain either karma or reputation.');
        }

        return $profile;
    }

    public function profileForColumns(
        bool $hasKarma,
        bool $hasReputation,
        bool $heroesAvailable,
        bool $castlesAvailable,
        bool $characterRescueAvailable = false,
        ?string $chronicle = null,
    ): ?MobiusGameSchemaProfile {
        if (! $hasKarma && ! $hasReputation) {
            return null;
        }

        if ($hasKarma && $hasReputation) {
            $reputationColumn = $this->preferredProfile($chronicle) === MobiusGameSchemaProfile::LEGACY
                ? 'karma'
                : 'reputation';
        } else {
            $reputationColumn = $hasKarma ? 'karma' : 'reputation';
        }

        return new MobiusGameSchemaProfile(
            name: $reputationColumn === 'karma'
                ? MobiusGameSchemaProfile::LEGACY
                : MobiusGameSchemaProfile::MODERN,
            reputationColumn: $reputationColumn,
            heroesAvailable: $heroesAvailable,
            castlesAvailable: $castlesAvailable,
            characterRescueAvailable: $characterRescueAvailable,
        );
    }

    private function preferredProfile(?string $chronicle): ?string
    {
        $normalized = mb_strtolower(trim((string) $chronicle));
        if ($normalized === '') {
            return null;
        }

        $legacyMarkers = [
            'c1',
            'c4',
            'interlude',
            'epilogue',
            'high five',
            'highfive',
        ];

        foreach ($legacyMarkers as $marker) {
            if (str_contains($normalized, $marker)) {
                return MobiusGameSchemaProfile::LEGACY;
            }
        }

        return MobiusGameSchemaProfile::MODERN;
    }

    /** @param list<string> $columns */
    private function assertRequiredTable(Builder $schema, string $table, array $columns): void
    {
        if (! $schema->hasTable($table)) {
            throw new RuntimeException("The Mobius game database does not contain the required {$table} table.");
        }

        $missingColumns = [];
        foreach ($columns as $column) {
            if (! $schema->hasColumn($table, $column)) {
                $missingColumns[] = $column;
            }
        }

        if ($missingColumns !== []) {
            throw new RuntimeException(sprintf(
                'The Mobius %s table is missing required columns: %s.',
                $table,
                implode(', ', $missingColumns),
            ));
        }
    }

    /** @param list<string> $columns */
    private function tableHasColumns(Builder $schema, string $table, array $columns): bool
    {
        if (! $schema->hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (! $schema->hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
}
