<?php

namespace App\Services\GameWorld;

use Illuminate\Database\Connection;
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
        // One column listing per table; an empty listing means the table does not exist.
        $schema = $connection->getSchemaBuilder();
        $columns = [];
        foreach (['characters', 'clan_data', 'heroes', 'castle'] as $table) {
            $columns[$table] = array_map('strtolower', $schema->getColumnListing($table));
        }

        $this->assertRequiredTable($columns['characters'], 'characters', self::CHARACTER_COLUMNS);
        $this->assertRequiredTable($columns['clan_data'], 'clan_data', self::CLAN_COLUMNS);

        return new RusacisGameSchemaProfile(
            heroesAvailable: $this->hasColumns($columns['heroes'], self::HERO_COLUMNS),
            castlesAvailable: $this->hasColumns($columns['castle'], self::CASTLE_COLUMNS),
        );
    }

    /**
     * @param  list<string>  $existing  lower-case column names of the table
     * @param  list<string>  $columns
     */
    private function assertRequiredTable(array $existing, string $table, array $columns): void
    {
        if ($existing === []) {
            throw new RuntimeException("The RUSaCis game database does not contain the required {$table} table.");
        }
        $missing = [];
        foreach ($columns as $column) {
            if (! in_array(strtolower($column), $existing, true)) {
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
