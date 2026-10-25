<?php

namespace App\Services\GameWorld;

use App\Support\UniversalDrivers\CastleNameCatalog;
use App\Support\UniversalDrivers\ProfileValidationException;
use App\Support\UniversalDrivers\ProfileValidator;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Experimental, read-only ranking/hero/castle adapter. The existing game
 * resolvers remain unchanged. SQL identifiers are validated JSON DATA;
 * query values are parameter-bound through Laravel's query builder.
 */
final class UniversalGameWorldReader
{
    private const RANKING_SECTIONS = ['level', 'pvp', 'pk', 'play_time'];

    public function __construct(
        private readonly ProfileValidator $validator = new ProfileValidator,
        private readonly CastleNameCatalog $castleNames = new CastleNameCatalog,
    ) {}

    /** @param array<string,mixed> $profile
     * @return array{missing_required:list<string>,missing_optional:list<string>}
     */
    public function diagnose(Connection $database, array $profile): array
    {
        $config = $this->configuration($database, $profile);
        $report = $this->schema($database, $config);

        return ['missing_required' => $report['missing_required'], 'missing_optional' => $report['missing_optional']];
    }

    /** @param array<string,mixed> $profile
     * @return list<string>
     */
    public function capabilities(Connection $database, array $profile): array
    {
        $config = $this->configuration($database, $profile);
        $schema = $this->schema($database, $config);
        $this->requireCore($schema);
        $supported = [];
        $base = $config['characters']['table'];
        $characterColumns = $schema['columns'][$base] ?? [];
        foreach (self::RANKING_SECTIONS as $section) {
            $rank = $config['rankings'][$section] ?? null;
            if (in_array($section, $config['capabilities'], true) && is_array($rank) &&
                $this->hasAll($characterColumns, array_values(array_filter([
                    $rank['column'] ?? null, $rank['secondary'] ?? null,
                ], 'is_string')))) {
                $supported[] = $section;
            }
        }
        foreach (['heroes', 'castles'] as $feature) {
            if (in_array($feature, $config['capabilities'], true) && $this->featureIsAvailable($config, $schema['columns'], $feature)) {
                $supported[] = $feature;
            }
        }
        $characters = $config['characters'];
        $columns = $schema['columns'][$characters['table']] ?? [];
        if (in_array('character_rescue', $config['capabilities'], true) &&
            $this->hasAll($columns, array_map(static fn (array $field): string => $field['column'], [
                $characters['fields']['x'], $characters['fields']['y'], $characters['fields']['z'],
            ]))) {
            $supported[] = 'character_rescue';
        }

        return $supported;
    }

    /** @param array<string,mixed> $profile
     * @return list<array<string,mixed>>
     */
    public function ranking(Connection $database, array $profile, string $section, int $limit, ?string $chronicle = null): array
    {
        if (! in_array($section, self::RANKING_SECTIONS, true)) {
            throw new InvalidArgumentException('Unsupported character ranking section.');
        }
        $config = $this->configuration($database, $profile, $section);
        $schema = $this->schema($database, $config);
        $this->requireCore($schema);
        $query = $this->characterQuery($database, $config, $schema['columns'], $chronicle);
        $rank = $config['rankings'][$section];
        $base = $config['characters']['table'];
        $rankColumn = $rank['column'];
        $secondary = $rank['secondary'];
        if (! $this->hasAll($schema['columns'][$base], array_values(array_filter([$rankColumn, $secondary])))) {
            throw new ProfileValidationException('Ranking column is missing from the character table.');
        }
        if ($rank['positive_only']) {
            $query->where('c.'.$rankColumn, '>', 0);
        }
        $query->orderByDesc('c.'.$rankColumn);
        if ($secondary !== null) {
            $query->orderByDesc('c.'.$secondary);
        }
        $query->orderBy('c.'.$config['characters']['fields']['name']['column']);
        $rows = $this->rows($query->limit(min(max($limit, 1), 100))->get());

        return $this->hydrateHero($database, $config, $schema['columns'], $rows);
    }

    /** @param array<string,mixed> $profile
     * @return list<array<string,mixed>>
     */
    public function heroes(Connection $database, array $profile, ?string $chronicle = null): array
    {
        $config = $this->configuration($database, $profile, 'heroes');
        $schema = $this->schema($database, $config);
        $this->requireCore($schema);
        if (! $this->featureIsAvailable($config, $schema['columns'], 'heroes')) {
            return [];
        }
        $feature = $config['features']['heroes'];
        $relation = $config['relations'][$feature['relation']];
        $query = $this->characterQuery($database, $config, $schema['columns'], $chronicle);
        $query->join($relation['table'].' as hero_record', 'hero_record.'.$relation['foreign_column'], '=', 'c.'.$relation['local_column']);
        if (array_key_exists('played_value', $feature)) {
            $query->where('hero_record.'.($feature['played_column'] ?? 'played'), $feature['played_value']);
        }
        if (array_key_exists('active_value', $feature)) {
            $query->where('hero_record.'.($feature['active_column'] ?? 'active'), $feature['active_value']);
        }
        $query->addSelect([
            'hero_record.'.($feature['count_column'] ?? 'count').' as hero_count',
            'hero_record.'.($feature['played_column'] ?? 'played').' as hero_played',
            'hero_record.'.($feature['claimed_column'] ?? ($feature['active_column'] ?? 'claimed')).' as hero_claimed',
        ])->orderByDesc('c.'.$config['characters']['fields']['level']['column'])
            ->orderBy('c.'.$config['characters']['fields']['name']['column']);

        return $this->hydrateHero($database, $config, $schema['columns'], $this->rows($query->get()));
    }

    /** @param array<string,mixed> $profile
     * @return list<array<string,mixed>>
     */
    public function castleOwners(Connection $database, array $profile): array
    {
        $config = $this->configuration($database, $profile, 'castles');
        $schema = $this->schema($database, $config);
        $this->requireCore($schema);
        if (! $this->featureIsAvailable($config, $schema['columns'], 'castles')) {
            return [];
        }
        $feature = $config['features']['castles'];
        $castle = $config['relations'][$feature['relation']];
        $clan = $config['relations'][$feature['clan_relation'] ?? 'clan'];
        $leader = $config['relations'][$feature['leader_relation'] ?? 'leader'];
        $clanColumns = $feature['clan_columns'] ?? [
            'id' => 'clan_id', 'name' => 'clan_name', 'level' => 'clan_level', 'reputation' => 'reputation_score',
        ];
        $leaderName = $feature['leader_name_column'] ?? 'char_name';
        // The profiles describe relations from the clan record: no SQL or
        // arbitrary join expression may be provided by an imported profile.
        $query = $database->table($clan['table'].' as clan')
            ->join($castle['table'].' as castle', 'castle.'.$castle['foreign_column'], '=', 'clan.'.$castle['local_column'])
            ->leftJoin($leader['table'].' as leader', 'leader.'.$leader['foreign_column'], '=', 'clan.'.$leader['local_column'])
            ->where('clan.'.$castle['local_column'], '>', 0)
            ->select([
                'castle.'.$castle['foreign_column'].' as castle_id',
                'clan.'.$clanColumns['id'].' as clan_id',
                'clan.'.$clanColumns['name'].' as clan_name',
                'clan.'.$clanColumns['level'].' as clan_level',
                'clan.'.$clanColumns['reputation'].' as reputation_score',
                'leader.'.$leaderName.' as leader_name',
            ])->orderBy('castle.'.$castle['foreign_column']);
        if ($feature['name_source'] === 'column') {
            $query->addSelect('castle.'.$feature['name_column'].' as castle_name');
        }
        $rows = $this->rows($query->get(), 'castle_id');
        if ($feature['name_source'] === 'catalog') {
            foreach ($rows as &$row) {
                $row['castle_name'] = $this->castleNames->name((int) $row['castle_id']);
            }
            unset($row);
        }

        return $rows;
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

    /** @param array<string,mixed> $config
     * @return array{columns:array<string,list<string>>,missing_required:list<string>,missing_optional:list<string>}
     */
    private function schema(Connection $database, array $config): array
    {
        $inspector = $database->getSchemaBuilder();
        $columns = [];
        $required = [];
        $optional = [];
        foreach ($config['requirements'] as $rule) {
            $table = $rule['table'];
            if (! $inspector->hasTable($table)) {
                $columns[$table] = [];
                if ($rule['required']) {
                    $required[] = 'table:'.$table;
                } else {
                    $optional[] = 'table:'.$table;
                }

                continue;
            }
            $columns[$table] = $inspector->getColumnListing($table);
            foreach (['columns', 'optional_columns', 'any_columns'] as $key) {
                if ($key === 'any_columns') {
                    if (isset($rule[$key]) && ! $this->hasAny($columns[$table], $rule[$key])) {
                        if ($rule['required']) {
                            $required[] = $table.'.{'.implode(',', $rule[$key]).'}';
                        } else {
                            $optional[] = $table.'.{'.implode(',', $rule[$key]).'}';
                        }
                    }

                    continue;
                }
                foreach ($rule[$key] ?? [] as $column) {
                    if (! $this->hasAll($columns[$table], [$column])) {
                        if ($key === 'optional_columns' || ! $rule['required']) {
                            $optional[] = $table.'.'.$column;
                        } else {
                            $required[] = $table.'.'.$column;
                        }
                    }
                }
            }
        }

        return ['columns' => $columns, 'missing_required' => $required, 'missing_optional' => $optional];
    }

    /** @param array{missing_required:list<string>} $schema */
    private function requireCore(array $schema): void
    {
        if ($schema['missing_required'] !== []) {
            throw new ProfileValidationException('Required GameServer schema is unavailable: '.implode(', ', $schema['missing_required']));
        }
    }

    /** @param array<string,mixed> $config
     * @param  array<string,list<string>>  $columns
     */
    private function featureIsAvailable(array $config, array $columns, string $featureName): bool
    {
        $feature = $config['features'][$featureName] ?? null;
        if ($feature === null) {
            return false;
        }
        $relation = $config['relations'][$feature['relation']];
        $available = $columns[$relation['table']] ?? [];
        if ($available === []) {
            return false;
        }
        $needed = $featureName === 'heroes'
            ? [$relation['foreign_column'], $feature['count_column'] ?? 'count', $feature['played_column'] ?? 'played', $feature['claimed_column'] ?? ($feature['active_column'] ?? 'claimed')]
            : [$relation['foreign_column'], ...($feature['name_source'] === 'column' ? [$feature['name_column']] : [])];
        if ($featureName === 'heroes') {
            if (! $this->hasAll($columns[$config['characters']['table']] ?? [], [$relation['local_column']])) {
                return false;
            }
            foreach (['played_column', 'active_column'] as $key) {
                if (isset($feature[$key])) {
                    $needed[] = $feature[$key];
                }
            }
        } else {
            $clan = $config['relations'][$feature['clan_relation'] ?? 'clan'] ?? null;
            $leader = $config['relations'][$feature['leader_relation'] ?? 'leader'] ?? null;
            if ($clan === null || $leader === null || $clan['table'] === '') {
                return false;
            }
            $clanCols = $feature['clan_columns'] ?? [
                'id' => 'clan_id', 'name' => 'clan_name', 'level' => 'clan_level', 'reputation' => 'reputation_score',
            ];
            if (! $this->hasAll($columns[$clan['table']] ?? [], [$relation['local_column'], $leader['local_column'], ...array_values($clanCols)])) {
                return false;
            }
            if (! $this->hasAll($columns[$leader['table']] ?? [], [$leader['foreign_column'], $feature['leader_name_column'] ?? 'char_name'])) {
                return false;
            }
        }

        return $this->hasAll($available, $needed);
    }

    /** @param array<string,mixed> $config
     * @param  array<string,list<string>>  $columns
     */
    private function characterQuery(Connection $database, array $config, array $columns, ?string $chronicle): Builder
    {
        $characters = $config['characters'];
        $table = $characters['table'];
        $fields = $characters['fields'];
        $mainColumns = $columns[$table] ?? [];
        $query = $database->table($table.' as c');
        foreach ($config['filters'] as $filter) {
            if (! $this->hasAll($mainColumns, [$filter['column']])) {
                throw new ProfileValidationException('A configured character filter column is missing: '.$filter['column']);
            }
            match ($filter['op']) {
                'eq' => $query->where('c.'.$filter['column'], '=', $filter['value']),
                'gt' => $query->where('c.'.$filter['column'], '>', $filter['value']),
                'zero_or_null' => $query->where(static fn (Builder $q): Builder => $q->whereNull('c.'.$filter['column'])->orWhere('c.'.$filter['column'], 0)),
                default => throw new ProfileValidationException('Unsupported character filter operation.'),
            };
        }
        $relationNames = [];
        foreach ($fields as $name => $field) {
            if ($field['kind'] === 'related') {
                $relationNames[$field['relation']] = true;
            }
        }
        foreach (array_keys($relationNames) as $name) {
            $relation = $config['relations'][$name];
            if ($relation['cardinality'] !== 'one') {
                throw new ProfileValidationException('Character relation must be single-row for world views.');
            }
            if (($columns[$relation['table']] ?? []) === []) {
                continue; // Optional data source absent; do not disable rankings.
            }
            if (! $this->hasAll($mainColumns, [$relation['local_column']]) ||
                ! $this->hasAll($columns[$relation['table']], [$relation['foreign_column']])) {
                throw new ProfileValidationException('Character relation key is unavailable.');
            }
            $query->leftJoin($relation['table'].' as r_'.$name, 'r_'.$name.'.'.$relation['foreign_column'], '=', 'c.'.$relation['local_column']);
        }
        foreach ($fields as $name => $field) {
            if ($field['kind'] === 'column' && $this->hasAll($mainColumns, [$field['column']])) {
                $query->addSelect('c.'.$field['column'].' as '.$name);
            } elseif ($field['kind'] === 'choice') {
                $choice = $this->chosenColumn($field, $mainColumns, $chronicle);
                if ($choice !== null) {
                    $query->addSelect('c.'.$choice.' as '.$name);
                }
            } elseif ($field['kind'] === 'related') {
                $relation = $config['relations'][$field['relation']];
                if (($columns[$relation['table']] ?? []) !== []) {
                    if (! $this->hasAll($columns[$relation['table']], [$field['column']])) {
                        throw new ProfileValidationException('Related field is unavailable: '.$field['column']);
                    }
                    $query->addSelect('r_'.$field['relation'].'.'.$field['column'].' as '.$name);
                } else {
                    $query->selectRaw('NULL as '.$database->getQueryGrammar()->wrap($name));
                }
            } elseif ($field['kind'] === 'operator' && $field['operator'] === 'rusacis_hero_active') {
                $query->addSelect('c.'.$config['characters']['fields']['id']['column'].' as __hero_character_id');
                if ($this->hasAll($mainColumns, ['herountil'])) {
                    $query->addSelect('c.herountil as __hero_until');
                }
            } elseif ($field['kind'] === 'eav') {
                throw new ProfileValidationException('EAV world ranking requires a separately verified aggregate mapping; use character lookup until implemented.');
            }
        }

        return $query;
    }

    /** @param iterable<object> $records
     * @return list<array<string,mixed>>
     */
    private function rows(iterable $records, string $identity = 'id'): array
    {
        $result = [];
        $seen = [];
        foreach ($records as $record) {
            $row = (array) $record;
            $key = (string) ($row[$identity] ?? '');
            if ($key === '' || isset($seen[$key])) {
                throw new ProfileValidationException('Duplicate or missing identity in world result.');
            }
            $seen[$key] = true;
            $result[] = $row;
        }

        return $result;
    }

    /** @param array<string,mixed> $config
     * @param  array<string,list<string>>  $columns
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function hydrateHero(Connection $database, array $config, array $columns, array $rows): array
    {
        if ($rows === [] || ! isset($config['characters']['fields']['hero'])) {
            return $rows;
        }
        $feature = $config['features']['heroes'] ?? null;
        $heroIds = [];
        if ($feature !== null && $this->featureIsAvailable($config, $columns, 'heroes')) {
            $relation = $config['relations'][$feature['relation']];
            $values = array_column($rows, '__hero_character_id');
            foreach ($database->table($relation['table'])
                ->whereIn($relation['foreign_column'], $values)
                ->where($feature['played_column'] ?? 'played', $feature['played_value'] ?? 1)
                ->where($feature['active_column'] ?? 'active', $feature['active_value'] ?? 1)
                ->get([$relation['foreign_column']]) as $item) {
                $heroIds[(string) $item->{$relation['foreign_column']}] = true;
            }
        }
        $now = now()->getTimestampMs();
        foreach ($rows as &$row) {
            $id = (string) ($row['__hero_character_id'] ?? '');
            $row['hero'] = (int) (((int) ($row['__hero_until'] ?? 0) > $now) || isset($heroIds[$id]));
            unset($row['__hero_character_id'], $row['__hero_until']);
        }
        unset($row);

        return $rows;
    }

    /** @param list<string> $available
     * @param  list<string>  $wanted
     */
    private function hasAll(array $available, array $wanted): bool
    {
        return array_diff(array_map('strtolower', $wanted), array_map('strtolower', $available)) === [];
    }

    /** @param list<string> $available
     * @param  list<string>  $wanted
     */
    private function hasAny(array $available, array $wanted): bool
    {
        return array_intersect(array_map('strtolower', $wanted), array_map('strtolower', $available)) !== [];
    }

    /** @param array<string,mixed> $field
     * @param  list<string>  $columns
     */
    private function chosenColumn(array $field, array $columns, ?string $chronicle): ?string
    {
        $present = array_values(array_filter($field['columns'], fn (string $column): bool => $this->hasAll($columns, [$column])));
        if ($present === []) {
            return null;
        }
        if ($field['preference'] === 'mobius_chronicle' && count($present) > 1) {
            $normalized = mb_strtolower(trim((string) $chronicle));
            foreach (['c1', 'c4', 'interlude', 'epilogue', 'high five', 'highfive'] as $legacy) {
                if ($normalized !== '' && str_contains($normalized, $legacy)) {
                    return in_array('karma', $present, true) ? 'karma' : $present[0];
                }
            }

            return in_array('reputation', $present, true) ? 'reputation' : $present[0];
        }

        return $present[0];
    }
}
