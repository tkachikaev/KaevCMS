<?php

namespace App\Services\GameWorld;

use App\Models\GameServer;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class MobiusGameSchemaInspector
{
    private const CACHE_MINUTES = 5;

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

    /**
     * The profile of a saved game server, remembered for a few minutes: inspecting reads
     * information_schema, which is slow on a remote or busy game database. Editing the server
     * changes updated_at and therefore the key.
     *
     * @param  Closure(): MobiusGameSchemaProfile  $inspect
     */
    public function remembered(GameServer $server, Closure $inspect): MobiusGameSchemaProfile
    {
        if (! $server->exists) {
            return $inspect();
        }

        $cacheKey = implode(':', [
            'mobius-game-schema-v1',
            $server->id,
            $server->updated_at?->getTimestamp() ?? 0,
        ]);

        /** @var array{name:string,reputation_column:string,heroes_available:bool,castles_available:bool,character_rescue_available:bool} $profile */
        $profile = Cache::remember(
            $cacheKey,
            now()->addMinutes(self::CACHE_MINUTES),
            static fn (): array => $inspect()->toArray(),
        );

        return MobiusGameSchemaProfile::fromArray($profile);
    }

    public function inspect(Connection $connection, ?string $chronicle = null): MobiusGameSchemaProfile
    {
        // One column listing per table; an empty listing means the table does not exist.
        $schema = $connection->getSchemaBuilder();
        $columns = [];
        foreach (['characters', 'clan_data', 'heroes', 'castle'] as $table) {
            $columns[$table] = array_map('strtolower', $schema->getColumnListing($table));
        }

        $this->assertRequiredTable($columns['characters'], 'characters', self::CHARACTER_COLUMNS);
        $this->assertRequiredTable($columns['clan_data'], 'clan_data', self::CLAN_COLUMNS);

        $hasKarma = in_array('karma', $columns['characters'], true);
        $hasReputation = in_array('reputation', $columns['characters'], true);

        if (! $hasKarma && ! $hasReputation) {
            throw new RuntimeException('The Mobius characters table must contain either karma or reputation.');
        }

        $profile = $this->profileForColumns(
            hasKarma: $hasKarma,
            hasReputation: $hasReputation,
            heroesAvailable: $this->hasColumns($columns['heroes'], self::HERO_COLUMNS),
            castlesAvailable: $this->hasColumns($columns['castle'], self::CASTLE_COLUMNS),
            characterRescueAvailable: $this->hasColumns($columns['characters'], [
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

    /**
     * @param  list<string>  $existing  lower-case column names of the table
     * @param  list<string>  $columns
     */
    private function assertRequiredTable(array $existing, string $table, array $columns): void
    {
        if ($existing === []) {
            throw new RuntimeException("The Mobius game database does not contain the required {$table} table.");
        }

        $missingColumns = [];
        foreach ($columns as $column) {
            if (! in_array(strtolower($column), $existing, true)) {
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

    /**
     * @param  list<string>  $existing  lower-case column names; empty when the table is missing
     * @param  list<string>  $columns
     */
    private function hasColumns(array $existing, array $columns): bool
    {
        if ($existing === []) {
            return false;
        }

        foreach ($columns as $column) {
            if (! in_array(strtolower($column), $existing, true)) {
                return false;
            }
        }

        return true;
    }
}
