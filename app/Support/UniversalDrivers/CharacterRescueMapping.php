<?php

namespace App\Support\UniversalDrivers;

use App\Support\GameServerFeatures\CharacterRescueCapabilities;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/** Direct-column coordinate writes through a verified character identity contract. */
final readonly class CharacterRescueMapping
{
    /** @param array<string,string> $columns
     * @param  array<string,mixed>  $config
     */
    private function __construct(
        public string $table,
        public array $columns,
        private array $config,
    ) {}

    /** @param array<string,mixed> $profile */
    public static function fromProfile(array $profile): ?self
    {
        if ($profile['type'] !== 'game' || ! in_array('characters', $profile['config']['capabilities'], true)) {
            return null;
        }
        $config = $profile['config'];
        $fields = $config['characters']['fields'];

        // Older bundled snapshots have no explicit safety-field mappings.
        // Match their actual identity and safety contract, not just the ID.
        if (! isset($fields['access_level']) && ! isset($fields['deletion_time'])) {
            $bundled = (new BundledProfileCatalog)->all()['game:'.$profile['id']] ?? null;
            $native = in_array($profile['id'], ['l2j_mobius', 'rusacis'], true) && $bundled !== null
                ? self::fromConfig($bundled['config'])
                : null;
            if ($native !== null && $config['characters']['table'] === $native->table
                && $config['characters']['owner']['identity'] === 'login'
                && $config['characters']['owner']['column'] === $native->columns['owner']) {
                foreach (['id', 'name', 'online', 'last_seen_at_ms', 'x', 'y', 'z'] as $name) {
                    if (! isset($fields[$name]) && in_array($name, ['x', 'y', 'z'], true) && $profile['id'] === 'rusacis') {
                        $fields[$name] = ['kind' => 'column', 'column' => $native->columns[$name]];
                    }
                    if (($fields[$name]['kind'] ?? null) !== 'column'
                        || ($fields[$name]['column'] ?? null) !== $native->columns[$name]) {
                        return null;
                    }
                }
                $fields['access_level'] = ['kind' => 'column', 'column' => $native->columns['access_level']];
                $fields['deletion_time'] = ['kind' => 'column', 'column' => $native->columns['deletion_time']];
                $config['characters']['fields'] = $fields;

                return self::fromConfig($config);
            }
        }

        return in_array('character_rescue', $config['capabilities'], true)
            ? self::fromConfig($config)
            : null;
    }

    /** @param array<string,mixed> $config */
    public static function fromConfig(array $config): ?self
    {
        $characters = $config['characters'];
        if ($characters['owner']['identity'] !== 'login') {
            return null;
        }
        $columns = ['owner' => $characters['owner']['column']];
        foreach (['id', 'name', 'online', 'last_seen_at_ms', 'access_level', 'deletion_time', 'x', 'y', 'z'] as $name) {
            $field = $characters['fields'][$name] ?? [];
            if (($field['kind'] ?? null) !== 'column' || ! is_string($field['column'] ?? null) || $field['column'] === '') {
                return null;
            }
            $columns[$name] = $field['column'];
        }
        if (count(array_unique(array_map('strtolower', $columns))) !== count($columns)) {
            return null;
        }
        $table = $characters['table'];
        $axes = array_map('strtolower', [$columns['x'], $columns['y'], $columns['z']]);
        foreach ($characters['fields'] as $name => $field) {
            if (! in_array($name, ['x', 'y', 'z'], true)
                && (($field['kind'] === 'column' && in_array(strtolower($field['column']), $axes, true))
                    || ($field['kind'] === 'choice' && array_intersect($axes, array_map('strtolower', $field['columns'])) !== []))) {
                return null;
            }
        }
        // Never allow coordinate writes to change a relation key.
        foreach ($config['relations'] as $relation) {
            if (in_array(strtolower($relation['local_column']), $axes, true)
                || ($relation['table'] === $table && in_array(strtolower($relation['foreign_column']), $axes, true))) {
                return null;
            }
        }
        $access = false;
        $deletion = false;
        foreach ($config['filters'] as $filter) {
            // Cross-table eligibility would require an additional locking contract.
            if (($filter['table'] ?? $table) !== $table || in_array(strtolower($filter['column']), $axes, true)) {
                return null;
            }
            $zero = $filter['op'] === 'eq' && in_array($filter['value'] ?? null, [0, '0'], true);
            $access = $access || (strcasecmp($filter['column'], $columns['access_level']) === 0 && $zero);
            $deletion = $deletion || (strcasecmp($filter['column'], $columns['deletion_time']) === 0
                && ($zero || $filter['op'] === 'zero_or_null'));
        }
        if (! $access || ! $deletion) {
            return null;
        }

        return new self($table, $columns, $config);
    }

    /** @return list<string> */
    private function requiredColumns(): array
    {
        return array_values(array_unique([...array_values($this->columns), ...array_column($this->config['filters'], 'column')]));
    }

    public function capabilities(Connection $connection): CharacterRescueCapabilities
    {
        $schema = $connection->getSchemaBuilder();
        $available = $schema->hasTable($this->table) ? $schema->getColumnListing($this->table) : [];
        $missing = [];
        foreach ($this->requiredColumns() as $column) {
            if (! in_array(strtolower($column), array_map('strtolower', $available), true)) {
                $missing[] = $column;
            }
        }
        if ($missing !== []) {
            return new CharacterRescueCapabilities(false, $missing);
        }
        if (! ProfileSchema::hasUniqueColumn($connection, $this->table, $this->columns['id'])) {
            return new CharacterRescueCapabilities(false);
        }
        if ($connection->getDriverName() === 'mysql') {
            $types = [];
            foreach ($schema->getColumns($this->table) as $column) {
                $types[strtolower($column['name'])] = strtolower($column['type_name']);
            }
            foreach ($this->columns as $field => $column) {
                $allowed = in_array($field, ['owner', 'name'], true)
                    ? ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext']
                    : ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];
                if (! in_array($types[strtolower($column)] ?? '', $allowed, true)) {
                    return new CharacterRescueCapabilities(false);
                }
            }
            $table = $connection->selectOne(
                'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$connection->getDatabaseName(), $connection->getTablePrefix().$this->table],
            );
            if (strtolower((string) ($table->engine ?? '')) !== 'innodb') {
                return new CharacterRescueCapabilities(false);
            }
        } elseif ($connection->getDriverName() !== 'sqlite') {
            return new CharacterRescueCapabilities(false);
        }

        return new CharacterRescueCapabilities(true);
    }

    public function characterQuery(Connection $connection, string $login, int $id): Builder
    {
        $query = $connection->table($this->table)
            ->where($this->columns['id'], $id)
            ->where($this->columns['owner'], $login);
        // All required columns have just passed schema verification. Reuse
        // exactly the same local eligibility filters as the character reader.
        GameCharacterFilters::apply($query, $this->config, [$this->table => $this->requiredColumns()], $this->table);

        return $query;
    }
}
