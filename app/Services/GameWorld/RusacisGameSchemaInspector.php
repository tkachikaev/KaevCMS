<?php

namespace App\Services\GameWorld;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use RuntimeException;

final class RusacisGameSchemaInspector
{
    private const CHARACTER_COLUMNS = [
        'account_name', 'obj_Id', 'char_name', 'level', 'exp', 'classid', 'race',
        'sex', 'title', 'online', 'onlinetime', 'accesslevel', 'deletetime',
        'pvpkills', 'pkkills', 'karma', 'nobless', 'herountil', 'clanid', 'lastAccess',
    ];

    private const CLAN_COLUMNS = [
        'clan_id', 'clan_name', 'clan_level', 'reputation_score', 'hasCastle', 'leader_id',
    ];

    private const HERO_COLUMNS = ['char_id', 'class_id', 'count', 'played', 'active'];

    private const CASTLE_COLUMNS = ['id'];

    /**
     * @return list<array{table:string,columns:list<string>,required:bool}>
     */
    public static function requirements(): array
    {
        return [
            ['table' => 'characters', 'columns' => self::CHARACTER_COLUMNS, 'required' => true],
            ['table' => 'clan_data', 'columns' => self::CLAN_COLUMNS, 'required' => true],
            ['table' => 'heroes', 'columns' => self::HERO_COLUMNS, 'required' => false],
            ['table' => 'castle', 'columns' => self::CASTLE_COLUMNS, 'required' => false],
        ];
    }

    public function inspect(Connection $connection): RusacisGameSchemaProfile
    {
        $schema = $connection->getSchemaBuilder();
        $this->assertRequiredTable($schema, 'characters', self::CHARACTER_COLUMNS);
        $this->assertRequiredTable($schema, 'clan_data', self::CLAN_COLUMNS);

        return new RusacisGameSchemaProfile(
            heroesAvailable: $this->hasColumns($schema, 'heroes', self::HERO_COLUMNS),
            castlesAvailable: $this->hasColumns($schema, 'castle', self::CASTLE_COLUMNS),
        );
    }

    /** @param list<string> $columns */
    private function assertRequiredTable(Builder $schema, string $table, array $columns): void
    {
        if (! $schema->hasTable($table)) {
            throw new RuntimeException("The RUSaCis game database does not contain the required {$table} table.");
        }
        $missing = [];
        foreach ($columns as $column) {
            if (! $schema->hasColumn($table, $column)) {
                $missing[] = $column;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'The RUSaCis %s table is missing required columns: %s.',
                $table,
                implode(', ', $missing),
            ));
        }
    }

    /** @param list<string> $columns */
    private function hasColumns(Builder $schema, string $table, array $columns): bool
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
