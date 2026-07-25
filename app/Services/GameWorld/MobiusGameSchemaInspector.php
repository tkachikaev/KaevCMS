<?php

namespace App\Services\GameWorld;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use RuntimeException;

final class MobiusGameSchemaInspector
{
    private const HERO_COLUMNS = ['charId', 'class_id', 'count', 'played', 'claimed'];

    private const CASTLE_COLUMNS = ['id', 'name'];

    private const CLAN_CASTLE_COLUMNS = ['clan_id', 'clan_name', 'clan_level', 'reputation_score', 'hasCastle', 'leader_id'];

    public function inspect(Connection $connection, ?string $chronicle = null): MobiusGameSchemaProfile
    {
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable('characters')) {
            throw new RuntimeException('The Mobius game database does not contain the required characters table.');
        }

        $hasKarma = $schema->hasColumn('characters', 'karma');
        $hasReputation = $schema->hasColumn('characters', 'reputation');

        if (! $hasKarma && ! $hasReputation) {
            throw new RuntimeException('The Mobius characters table must contain either karma or reputation.');
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
            heroesAvailable: $this->tableHasColumns($schema, 'heroes', self::HERO_COLUMNS),
            castlesAvailable: $this->tableHasColumns($schema, 'castle', self::CASTLE_COLUMNS)
                && $this->tableHasColumns($schema, 'clan_data', self::CLAN_CASTLE_COLUMNS),
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
