<?php

namespace App\Services\GameWorld;

use App\Support\UniversalDrivers\GameCharacterFilters;
use App\Support\UniversalDrivers\ProfileValidationException;
use App\Support\UniversalDrivers\ProfileValidator;
use Illuminate\Database\Connection;

/**
 * Experimental read-only character adapter. It does not participate in the
 * existing GameWorldDriverResolver until the explicit migration iteration.
 * It receives an already established game connection, never an arbitrary DSN.
 */
final class UniversalGameCharacterReader
{
    // Bound character reads must not cause unbounded scans on a game server.
    private const MAX_ACCOUNT_CHARACTERS = 1000;

    public function __construct(private readonly ProfileValidator $validator = new ProfileValidator) {}

    /**
     * @param  array<string,mixed>  $profile
     * @return array{missing_required:list<string>,missing_optional:list<string>}
     */
    public function diagnose(Connection $database, array $profile): array
    {
        $config = $this->configuration($database, $profile);
        $report = $this->schema($database, $config);

        return [
            'missing_required' => $report['missing_required'],
            'missing_optional' => $report['missing_optional'],
        ];
    }

    /**
     * The identity MUST come from a previously verified LoginServer account.
     * Caller remains responsible for account/character authorization; this
     * reader cannot validate ownership of a CMS user on its own.
     *
     * @param  array<string,mixed>  $profile
     * @return list<array<string,mixed>>
     */
    public function charactersForAccount(
        Connection $database,
        array $profile,
        string|int $accountIdentity,
        int $limit = 100,
        ?string $chronicle = null,
    ): array {
        $config = $this->configuration($database, $profile, 'characters');
        $schema = $this->schema($database, $config);
        if ($schema['missing_required'] !== []) {
            throw new ProfileValidationException('Required GameServer schema is unavailable: '.implode(', ', $schema['missing_required']));
        }

        $characters = $config['characters'];
        $owner = $characters['owner'];
        if ($owner['identity'] === 'id') {
            if ((is_int($accountIdentity) && $accountIdentity < 0) || (is_string($accountIdentity) && ($accountIdentity === '' || ! ctype_digit($accountIdentity)))) {
                throw new ProfileValidationException('Account ownership requires a numeric account ID.');
            }
        } elseif (! is_string($accountIdentity) || $accountIdentity === '') {
            throw new ProfileValidationException('Account ownership requires a login.');
        }

        $table = $characters['table'];
        $columns = $schema['columns'][$table] ?? [];
        $fields = $characters['fields'];
        $idColumn = $fields['id']['column'];
        $nameColumn = $fields['name']['column'];
        foreach ([$owner['column'], $idColumn, $nameColumn] as $required) {
            if (! $this->hasColumn($columns, $required)) {
                throw new ProfileValidationException('Required character identity column is unavailable: '.$table.'.'.$required);
            }
        }
        $selected = [$owner['column'], $idColumn, $nameColumn];
        foreach ($fields as $field) {
            if ($field['kind'] === 'column' && $this->hasColumn($columns, $field['column'])) {
                $selected[] = $field['column'];
            }
            if ($field['kind'] === 'choice') {
                $choice = $this->chosenColumn($field, $columns, $chronicle);
                if ($choice !== null) {
                    $selected[] = $choice;
                }
            }
            if ($field['kind'] === 'operator' && $field['operator'] === 'rusacis_hero_active' && $this->hasColumn($columns, 'herountil')) {
                $selected[] = 'herountil';
            }
        }
        foreach ($config['relations'] as $name => $relation) {
            if ($this->usesRelation($fields, $name) && $this->hasColumn($columns, $relation['local_column'])) {
                $selected[] = $relation['local_column'];
            }
        }
        $query = $database->table($table)->where($owner['column'], $accountIdentity);
        GameCharacterFilters::apply($query, $config, $schema['columns'], $table);
        // The limit is applied after related/EAV values have been populated,
        // because level may come from another table. Reject oversized account
        // datasets rather than returning a misleading partial list.
        $baseRows = $query->limit(self::MAX_ACCOUNT_CHARACTERS + 1)
            ->get(array_values(array_unique($selected)))
            ->map(static fn (object $row): array => (array) $row)->all();
        if (count($baseRows) > self::MAX_ACCOUNT_CHARACTERS) {
            throw new ProfileValidationException('Too many character rows for a bounded account lookup.');
        }

        $seen = [];
        foreach ($baseRows as $row) {
            $id = $row[$idColumn] ?? null;
            if ($id === null || isset($seen[(string) $id])) {
                throw new ProfileValidationException('Ambiguous or missing character identity.');
            }
            $seen[(string) $id] = true;
        }

        $related = $this->relatedValues($database, $config, $schema['columns'], $baseRows);
        $heroIds = $this->activeHeroIds($database, $config, $schema['columns'], $baseRows, $idColumn);
        $result = [];
        foreach ($baseRows as $row) {
            $mapped = [];
            foreach ($fields as $name => $field) {
                $mapped[$name] = match ($field['kind']) {
                    'column' => $row[$field['column']] ?? null,
                    'choice' => ($choice = $this->chosenColumn($field, $columns, $chronicle)) !== null ? ($row[$choice] ?? null) : null,
                    'related', 'eav' => $this->relatedValue($related, $config, $row, $field, $name),
                    'operator' => $this->heroValue($row, $idColumn, $heroIds),
                    default => throw new ProfileValidationException('Unsupported character field source.'),
                };
            }
            $result[] = $mapped;
        }
        // Existing PHP GameWorldDrivers sort account characters by level
        // descending and by name ascending. Sort after mapping for EAV level.
        usort($result, static function (array $a, array $b): int {
            $byLevel = (int) ($b['level'] ?? 0) <=> (int) ($a['level'] ?? 0);

            return $byLevel !== 0 ? $byLevel : strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return array_slice($result, 0, min(100, max(1, $limit)));
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array{columns:array<string,list<string>>,missing_required:list<string>,missing_optional:list<string>}
     */
    private function schema(Connection $database, array $config): array
    {
        $inspector = $database->getSchemaBuilder();
        $columns = [];
        $missingRequired = [];
        $missingOptional = [];
        foreach ($config['requirements'] as $requirement) {
            $table = $requirement['table'];
            if (! $inspector->hasTable($table)) {
                $columns[$table] = [];
                if ($requirement['required']) {
                    $missingRequired[] = 'table:'.$table;
                } else {
                    $missingOptional[] = 'table:'.$table;
                }

                continue;
            }
            $columns[$table] = $inspector->getColumnListing($table);
            foreach ($requirement['columns'] as $column) {
                if (! $this->hasColumn($columns[$table], $column)) {
                    if ($requirement['required']) {
                        $missingRequired[] = $table.'.'.$column;
                    } else {
                        $missingOptional[] = $table.'.'.$column;
                    }
                }
            }
            foreach ($requirement['optional_columns'] ?? [] as $column) {
                if (! $this->hasColumn($columns[$table], $column)) {
                    $missingOptional[] = $table.'.'.$column;
                }
            }
            $options = $requirement['any_columns'] ?? [];
            if ($options !== [] && ! $this->hasAnyColumn($columns[$table], $options)) {
                if ($requirement['required']) {
                    $missingRequired[] = $table.'.{'.implode(',', $options).'}';
                } else {
                    $missingOptional[] = $table.'.{'.implode(',', $options).'}';
                }
            }
        }

        return ['columns' => $columns, 'missing_required' => $missingRequired, 'missing_optional' => $missingOptional];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $columns
     * @return array<string,array<string,array<string,mixed>>>
     */
    private function relatedValues(Connection $database, array $config, array $columns, array $rows): array
    {
        $results = [];
        if ($rows === []) {
            return $results;
        }
        $fields = $config['characters']['fields'];
        $baseColumns = $columns[$config['characters']['table']] ?? [];
        foreach ($config['relations'] as $name => $relation) {
            $mapped = array_filter($fields, static fn (array $field): bool => in_array($field['kind'], ['related', 'eav'], true) && $field['relation'] === $name);
            if ($mapped === []) {
                continue;
            }
            if (! $this->hasColumn($baseColumns, $relation['local_column'])) {
                throw new ProfileValidationException('Related lookup local key is unavailable: '.$relation['local_column']);
            }
            $table = $relation['table'];
            if (! array_key_exists($table, $columns)) {
                throw new ProfileValidationException('Mapped relation must declare its table in schema requirements: '.$table);
            }
            $available = $columns[$table];
            if ($available === []) {
                // Missing optional relation: no associated fields for that row.
                // A required relation is already rejected by schema().
                continue;
            }
            if (! $this->hasColumn($available, $relation['foreign_column'])) {
                throw new ProfileValidationException('Related lookup foreign key is unavailable: '.$table.'.'.$relation['foreign_column']);
            }
            $keys = array_values(array_unique(array_filter(array_column($rows, $relation['local_column']), static fn (mixed $v): bool => $v !== null && $v !== '')));
            if ($keys === []) {
                continue;
            }
            $ordinary = array_filter($mapped, static fn (array $item): bool => $item['kind'] === 'related');
            $eav = array_filter($mapped, static fn (array $item): bool => $item['kind'] === 'eav');
            if ($ordinary !== [] && ($relation['cardinality'] !== 'one' || $eav !== [])) {
                throw new ProfileValidationException('Related values require a single-row relation, separate from EAV.');
            }
            if ($ordinary !== []) {
                $select = [$relation['foreign_column']];
                foreach ($ordinary as $item) {
                    if (! $this->hasColumn($available, $item['column'])) {
                        throw new ProfileValidationException('Related field column is unavailable: '.$table.'.'.$item['column']);
                    }
                    $select[] = $item['column'];
                }
                $relatedRows = $database->table($table)->whereIn($relation['foreign_column'], $keys)
                    ->get(array_values(array_unique($select)));
                foreach ($relatedRows as $relatedRow) {
                    $data = (array) $relatedRow;
                    $key = (string) $data[$relation['foreign_column']];
                    if (isset($results[$name][$key])) {
                        throw new ProfileValidationException('Non-unique related row violates relation cardinality: '.$name);
                    }
                    $results[$name][$key] = $data;
                }
            }
            if ($eav !== []) {
                if ($relation['cardinality'] !== 'many') {
                    throw new ProfileValidationException('EAV lookup requires a many-row relation.');
                }
                foreach ($eav as $fieldName => $item) {
                    foreach (['key_column', 'value_column'] as $columnName) {
                        if (! $this->hasColumn($available, $item[$columnName])) {
                            throw new ProfileValidationException('EAV column is unavailable: '.$table.'.'.$item[$columnName]);
                        }
                    }
                    $values = $database->table($table)
                        ->whereIn($relation['foreign_column'], $keys)
                        ->where($item['key_column'], $item['key_value'])
                        ->get([$relation['foreign_column'], $item['value_column']]);
                    foreach ($values as $value) {
                        $data = (array) $value;
                        $key = (string) $data[$relation['foreign_column']];
                        if (array_key_exists($key, $results[$name.':eav:'.$fieldName] ?? [])) {
                            throw new ProfileValidationException('Ambiguous EAV value for character and key: '.$fieldName);
                        }
                        $results[$name.':eav:'.$fieldName][$key] = ['value' => $data[$item['value_column']]];
                    }
                }
            }
        }

        return $results;
    }

    /** @param array<string,array<string,array<string,mixed>>> $related
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $field
     */
    private function relatedValue(array $related, array $config, array $row, array $field, string $name): mixed
    {
        $relation = $config['relations'][$field['relation']];
        $key = $row[$relation['local_column']] ?? null;
        if ($key === null) {
            return null;
        }
        $lookup = $field['relation'].($field['kind'] === 'eav' ? ':eav:'.$name : '');
        $data = $related[$lookup][(string) $key] ?? null;

        return $data[$field['kind'] === 'eav' ? 'value' : $field['column']] ?? null;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $columns
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,bool>
     */
    private function activeHeroIds(Connection $database, array $config, array $columns, array $rows, string $idColumn): array
    {
        $fields = $config['characters']['fields'];
        if (! isset($fields['hero']) || $fields['hero']['kind'] !== 'operator' || $rows === []) {
            return [];
        }
        $feature = $config['features']['heroes'] ?? null;
        $relation = is_array($feature) ? ($config['relations'][$feature['relation']] ?? null) : null;
        if ($relation === null) {
            return [];
        }
        $available = $columns[$relation['table']] ?? [];
        $playedColumn = $feature['played_column'] ?? 'played';
        $activeColumn = $feature['active_column'] ?? 'active';
        $needed = [$relation['foreign_column'], $playedColumn, $activeColumn];
        if ($available === [] || ! $this->hasAllColumns($available, $needed)) {
            return [];
        }
        $ids = [];
        $values = array_column($rows, $idColumn);
        foreach ($database->table($relation['table'])->whereIn($relation['foreign_column'], $values)
            ->where($playedColumn, $feature['played_value'] ?? 1)
            ->where($activeColumn, $feature['active_value'] ?? 1)
            ->get([$relation['foreign_column']]) as $item) {
            $ids[(string) $item->{$relation['foreign_column']}] = true;
        }

        return $ids;
    }

    /** @param array<string,mixed> $row
     * @param  array<string,bool>  $heroIds
     */
    private function heroValue(array $row, string $idColumn, array $heroIds): int
    {
        return ((int) ($row['herountil'] ?? 0) > (int) floor(microtime(true) * 1000)
            || isset($heroIds[(string) ($row[$idColumn] ?? '')])) ? 1 : 0;
    }

    /** @param array<string,mixed> $field
     * @param  list<string>  $columns
     */
    private function chosenColumn(array $field, array $columns, ?string $chronicle): ?string
    {
        $available = array_values(array_filter($field['columns'], fn (string $candidate): bool => $this->hasColumn($columns, $candidate)));
        if ($available === []) {
            return null;
        }
        if ($field['preference'] === 'mobius_chronicle' && count($available) > 1) {
            $chronicle = mb_strtolower(trim((string) $chronicle));
            foreach (['c1', 'c4', 'interlude', 'epilogue', 'high five', 'highfive'] as $legacy) {
                if ($chronicle !== '' && str_contains($chronicle, $legacy)) {
                    return in_array('karma', $available, true) ? 'karma' : $available[0];
                }
            }

            return in_array('reputation', $available, true) ? 'reputation' : $available[0];
        }

        return $available[0];
    }

    /** @param array<string,mixed> $fields */
    private function usesRelation(array $fields, string $relation): bool
    {
        foreach ($fields as $field) {
            if (in_array($field['kind'], ['related', 'eav'], true) && $field['relation'] === $relation) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $columns
     * @param  list<string>  $needed
     */
    private function hasAnyColumn(array $columns, array $needed): bool
    {
        foreach ($needed as $column) {
            if ($this->hasColumn($columns, $column)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $columns
     * @param  list<string>  $needed
     */
    private function hasAllColumns(array $columns, array $needed): bool
    {
        foreach ($needed as $column) {
            if (! $this->hasColumn($columns, $column)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $columns */
    private function hasColumn(array $columns, string $name): bool
    {
        return in_array(strtolower($name), array_map('strtolower', $columns), true);
    }

    /** @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function configuration(Connection $database, array $profile, ?string $capability = null): array
    {
        $this->validator->validate($profile);
        if ($profile['type'] !== 'game' || $profile['config']['database']['dialect'] !== 'mysql' || $database->getDriverName() !== 'mysql') {
            throw new ProfileValidationException('The selected profile requires a MySQL GameServer connection.');
        }
        if ($capability !== null && ! in_array($capability, $profile['config']['capabilities'], true)) {
            throw new ProfileValidationException('The game profile does not support the requested operation.');
        }

        return $profile['config'];
    }
}
