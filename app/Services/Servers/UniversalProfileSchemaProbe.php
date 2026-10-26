<?php

namespace App\Services\Servers;

use App\Support\UniversalDrivers\ProfileFormat;
use Illuminate\Database\Connection;

/**
 * Read-only checks for schema properties that a table/column presence probe cannot prove.
 * No external table is created, updated or locked. These are structural guarantees,
 * not proof that a live game operation will succeed on every possible dataset.
 */
final class UniversalProfileSchemaProbe
{
    /** @param array<string,mixed> $profile
     * @return list<array{key:string,replace:array<string,int|string>}>
     */
    public function problems(Connection $connection, array $profile): array
    {
        $config = $profile['config'];
        $schema = $connection->getSchemaBuilder();
        $problems = [];

        if ($profile['type'] === 'login') {
            $table = $config['accounts']['table'];
            if (! $schema->hasTable($table)) {
                return [['key' => 'drivers.schema_missing_table', 'replace' => ['table' => $table]]];
            }
            $fields = $config['accounts']['fields'];
            $columns = $schema->getColumnListing($table);
            foreach (['login', ...($this->writable($config) ? ['password'] : [])] as $field) {
                if (! is_string($fields[$field] ?? null) || ! in_array($fields[$field], $columns, true)) {
                    $problems[] = ['key' => 'drivers.schema_missing_account_field', 'replace' => ['field' => $field]];
                }
            }
            if ($this->writable($config)) {
                $loginColumn = $fields['login'];
                $unique = false;
                foreach ($schema->getIndexes($table) as $index) {
                    if ($index['unique'] && $index['columns'] === [$loginColumn]) {
                        $unique = true;
                        break;
                    }
                }
                if (! $unique) {
                    $problems[] = ['key' => 'drivers.schema_unique_login', 'replace' => ['column' => $table.'.'.$loginColumn]];
                }
                $length = $this->credentialLength($config['password']['scheme'] ?? '');
                $passwordColumn = $fields['password'] ?? null;
                foreach ($schema->getColumns($table) as $definition) {
                    if (! is_string($passwordColumn) || strcasecmp($definition['name'], $passwordColumn) !== 0) {
                        continue;
                    }
                    $type = $definition['type'];
                    if (preg_match('/\A(?:var)?(?:char|binary)\((\d+)\)/i', $type, $matches) === 1
                        && (int) $matches[1] < $length) {
                        $problems[] = ['key' => 'drivers.schema_password_length', 'replace' => ['column' => $table.'.'.$passwordColumn, 'actual' => (int) $matches[1], 'required' => $length]];
                    } elseif (preg_match('/\A(?:(?:var)?(?:char|binary)\(\d+\)|(?:tiny|medium|long)?(?:text|blob))\z/i', $type) !== 1) {
                        $problems[] = ['key' => 'drivers.schema_password_type', 'replace' => ['column' => $table.'.'.$passwordColumn]];
                    }
                    break;
                }
            }
            if (in_array('account_creation', $config['capabilities'], true)) {
                foreach (array_unique(array_merge(array_keys($config['accounts']['create']['columns'] ?? []), array_keys($config['accounts']['create']['fixed_values'] ?? []))) as $column) {
                    if (! in_array($column, $columns, true)) {
                        $problems[] = ['key' => 'drivers.schema_missing_column', 'replace' => ['column' => $table.'.'.$column]];
                    }
                }
            }

            return $problems;
        }

        $table = $config['characters']['table'];
        if (! $schema->hasTable($table)) {
            return [['key' => 'drivers.schema_missing_table', 'replace' => ['table' => $table]]];
        }
        $columns = $schema->getColumnListing($table);
        foreach (['level', 'pvp', 'pk', 'play_time'] as $capability) {
            if (! in_array($capability, $config['capabilities'], true)) {
                continue;
            }
            $ranking = $config['rankings'][$capability] ?? null;
            if (! is_array($ranking)) {
                $problems[] = ['key' => 'drivers.schema_missing_ranking', 'replace' => ['ranking' => $capability]];

                continue;
            }
            foreach ([$ranking['column'] ?? null, $ranking['secondary'] ?? null] as $column) {
                if (is_string($column) && ! in_array($column, $columns, true)) {
                    $problems[] = ['key' => 'drivers.schema_missing_column', 'replace' => ['column' => $table.'.'.$column]];
                }
            }
        }
        foreach (['heroes', 'castles'] as $capability) {
            if (! in_array($capability, $config['capabilities'], true)) {
                continue;
            }
            $feature = $config['features'][$capability] ?? [];
            $relation = $config['relations'][$feature['relation'] ?? ''] ?? null;
            if (! is_array($relation) || ! $schema->hasTable($relation['table'])) {
                $problems[] = ['key' => 'drivers.schema_missing_relation', 'replace' => ['relation' => $feature['relation'] ?? $capability]];

                continue;
            }
            // Castles are joined from the clan table; heroes are joined from
            // characters. Checking both against characters rejects real RUSaCis schemas.
            $sourceTable = $capability === 'castles'
                ? ($config['relations'][$feature['clan_relation'] ?? 'clan']['table'] ?? $table)
                : $table;
            $sourceColumns = $sourceTable === $table ? $columns
                : ($schema->hasTable($sourceTable) ? $schema->getColumnListing($sourceTable) : []);
            if (! in_array($relation['local_column'], $sourceColumns, true)) {
                $problems[] = ['key' => 'drivers.schema_missing_column', 'replace' => ['column' => $sourceTable.'.'.$relation['local_column']]];
            }
            $relatedColumns = $schema->getColumnListing($relation['table']);
            if (! in_array($relation['foreign_column'], $relatedColumns, true)) {
                $problems[] = ['key' => 'drivers.schema_missing_column', 'replace' => ['column' => $relation['table'].'.'.$relation['foreign_column']]];
            }
            $requiredTables = [];
            if ($capability === 'heroes') {
                $requiredTables[$relation['table']] = [
                    $feature['played_column'] ?? 'played', $feature['count_column'] ?? 'count',
                    $feature['claimed_column'] ?? ($feature['active_column'] ?? 'claimed'),
                    ...(array_key_exists('active_value', $feature) ? [$feature['active_column'] ?? 'active'] : []),
                ];
            } else {
                $clan = $config['relations'][$feature['clan_relation'] ?? 'clan'] ?? null;
                $leader = $config['relations'][$feature['leader_relation'] ?? 'leader'] ?? null;
                if (! is_array($clan) || ! is_array($leader)) {
                    $problems[] = ['key' => 'drivers.schema_missing_relation', 'replace' => ['relation' => ! is_array($clan) ? 'clan' : 'leader']];

                    continue;
                }
                $requiredTables[$clan['table']] = [
                    ...array_values($feature['clan_columns'] ?? ['id' => 'clan_id', 'name' => 'clan_name', 'level' => 'clan_level', 'reputation' => 'reputation_score']),
                    $leader['local_column'],
                ];
                $requiredTables[$leader['table']] = array_merge($requiredTables[$leader['table']] ?? [], [$leader['foreign_column'], $feature['leader_name_column'] ?? 'char_name']);
                if ($feature['name_source'] === 'column') {
                    $requiredTables[$relation['table']] = [$feature['name_column']];
                }
            }
            foreach ($requiredTables as $requiredTable => $requiredColumns) {
                if (! $schema->hasTable($requiredTable)) {
                    $problems[] = ['key' => 'drivers.schema_missing_table', 'replace' => ['table' => $requiredTable]];

                    continue;
                }
                $available = $schema->getColumnListing($requiredTable);
                foreach (array_unique($requiredColumns) as $column) {
                    if (! in_array($column, $available, true)) {
                        $problems[] = ['key' => 'drivers.schema_missing_column', 'replace' => ['column' => $requiredTable.'.'.$column]];
                    }
                }
            }
        }

        return $problems;
    }

    /** @param array<string,mixed> $config */
    private function writable(array $config): bool
    {
        return in_array('account_creation', $config['capabilities'], true)
            || in_array('password_change', $config['capabilities'], true);
    }

    private function credentialLength(string $scheme): int
    {
        return match ($scheme) {
            'sha1_base64' => 28,
            'bcrypt_2a' => 60,
            default => ProfileFormat::HEX_PASSWORD_SCHEMES[$scheme][1] ?? 0,
        };
    }
}
