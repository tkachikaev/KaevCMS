<?php

namespace App\Support\UniversalDrivers;

final class GameProfileEditor
{
    /** @param array<string,mixed> $profile
     * @param  array<string,mixed>  $original
     * @return array<string,mixed>
     */
    public function prepare(array $profile, array $original): array
    {
        foreach ($profile['config']['characters']['fields'] as $name => &$field) {
            if (! is_array($field)) {
                throw new ProfileValidationException('drivers.game_invalid_field');
            }
            $field['kind'] ??= 'column';
            if ($field['kind'] === 'column' && trim((string) ($field['column'] ?? '')) === '' && ! in_array($name, ['id', 'name'], true)) {
                unset($profile['config']['characters']['fields'][$name]);
            }
        }
        unset($field);
        $profile = (new ProfileEditorDraft)->clean($profile);
        $profile['config'] = $this->alignReferences($profile['config'], $original);
        $config = &$profile['config'];
        (new ProfileValidator)->validateGameFilters($config);
        $table = $config['characters']['table'];
        $oldTable = $original['characters']['table'] ?? $table;
        $rules = [];
        $this->column($rules, $table, $config['characters']['owner']['column']);
        $fields = $config['characters']['fields'];
        $hasEav = in_array('eav', array_column($fields, 'kind'), true);
        foreach ($fields as $name => $field) {
            if ($field['kind'] === 'column') {
                $optional = ! in_array($name, ['id', 'name'], true)
                    && $this->wasOptional($original, $oldTable, $original['characters']['fields'][$name]['column'] ?? '');
                $this->column($rules, $table, $field['column'], optional: $optional);
            } elseif ($field['kind'] === 'choice') {
                if (isset($rules[$table]['any_columns'])) {
                    throw new ProfileValidationException('drivers.game_multiple_choices');
                }
                $rules[$table]['any_columns'] = $field['columns'];
            } elseif (in_array($field['kind'], ['related', 'eav'], true)) {
                $relation = $config['relations'][$field['relation']] ?? null;
                if ($relation === null) {
                    throw new ProfileValidationException('drivers.game_relation_missing');
                }
                if ($relation['cardinality'] !== ($field['kind'] === 'eav' ? 'many' : 'one')) {
                    throw new ProfileValidationException('drivers.game_relation_cardinality');
                }
                $this->column($rules, $table, $relation['local_column']);
                $this->column($rules, $relation['table'], $relation['foreign_column']);
                foreach ($field['kind'] === 'eav' ? ['key_column', 'value_column'] : ['column'] as $key) {
                    $this->column($rules, $relation['table'], $field[$key]);
                }
            } elseif ($field['kind'] === 'operator') {
                $this->column($rules, $table, 'herountil', optional: true);
            }
        }
        foreach ($config['filters'] as $filter) {
            $filterTable = $filter['table'] ?? $table;
            if ($filterTable !== $table && (! is_array($filter['link'] ?? null)
                || empty($filter['link']['local_column']) || empty($filter['link']['foreign_column']))) {
                throw new ProfileValidationException('drivers.filter_link_required');
            }
        }
        foreach (GameCharacterFilters::requirements($config) as $filterTable => $filterColumns) {
            foreach ($filterColumns as $column) {
                $this->column($rules, $filterTable, $column);
            }
        }
        $capabilities = ['characters'];
        $rankings = [];
        foreach (['level' => 'level', 'pvp' => 'pvp_kills', 'pk' => 'pk_kills', 'play_time' => 'play_time_seconds'] as $rank => $fieldName) {
            if ($hasEav || ($fields[$fieldName]['kind'] ?? null) !== 'column') {
                continue;
            }
            $ranking = ($config['rankings'][$rank] ?? []) + ['secondary' => null, 'positive_only' => $rank !== 'level'];
            $ranking['column'] = $fields[$fieldName]['column'];
            foreach ($original['characters']['fields'] ?? [] as $oldName => $oldField) {
                if (($oldField['kind'] ?? '') === 'column' && ($ranking['secondary'] ?? null) === $oldField['column']
                    && ($fields[$oldName]['kind'] ?? '') === 'column') {
                    $ranking['secondary'] = $fields[$oldName]['column'];
                    break;
                }
            }
            if (($ranking['secondary'] ?? null) === '') {
                $ranking['secondary'] = null;
            }
            if ($ranking['secondary'] !== null) {
                $this->column($rules, $table, $ranking['secondary']);
            }
            $rankings[$rank] = $ranking;
            $capabilities[] = $rank;
        }
        $config['rankings'] = $rankings;
        foreach ($config['features'] as $name => $feature) {
            $relation = $config['relations'][$feature['relation']] ?? null;
            if ($relation === null) {
                throw new ProfileValidationException('drivers.game_relation_missing');
            }
            $this->column($rules, $relation['table'], $relation['foreign_column'], required: false);
            if ($name === 'heroes') {
                if (! $hasEav && ($fields['level']['kind'] ?? null) !== 'column') {
                    throw new ProfileValidationException('drivers.game_heroes_level');
                }
                $this->column($rules, $table, $relation['local_column']);
                foreach (array_unique([
                    $feature['played_column'] ?? 'played', $feature['count_column'] ?? 'count',
                    $feature['claimed_column'] ?? ($feature['active_column'] ?? 'claimed'),
                    ...(isset($feature['active_column']) ? [$feature['active_column']] : []),
                    ...(($fields['hero']['kind'] ?? '') === 'operator' ? [$feature['active_column'] ?? 'active'] : []),
                ]) as $column) {
                    $this->column($rules, $relation['table'], $column, required: false);
                }
            } elseif ($name === 'castles') {
                $clan = $config['relations'][$feature['clan_relation'] ?? 'clan'] ?? null;
                $leader = $config['relations'][$feature['leader_relation'] ?? 'leader'] ?? null;
                if ($clan === null || $leader === null) {
                    throw new ProfileValidationException('drivers.game_relation_missing');
                }
                foreach ([...array_values($feature['clan_columns'] ?? ['id' => 'clan_id', 'name' => 'clan_name', 'level' => 'clan_level', 'reputation' => 'reputation_score']), $relation['local_column'], $leader['local_column']] as $column) {
                    $this->column($rules, $clan['table'], $column);
                }
                $this->column($rules, $leader['table'], $leader['foreign_column']);
                $this->column($rules, $leader['table'], $feature['leader_name_column'] ?? 'char_name');
                if ($feature['name_source'] === 'column') {
                    $this->column($rules, $relation['table'], $feature['name_column'], required: false);
                }
            }
            if ($name !== 'heroes' || ! $hasEav) {
                $capabilities[] = $name;
            }
        }
        // Coordinate display does not enable the unsupported JSON rescue writer.
        $config['capabilities'] = $capabilities;
        $config['requirements'] = array_values($rules);

        return $profile;
    }

    /** @param array<string,mixed> $config
     * @param  array<string,mixed>  $original
     * @return array<string,mixed>
     */
    public function alignReferences(array $config, array $original): array
    {
        $columns = [];
        foreach ($original['characters']['fields'] ?? [] as $name => $old) {
            $current = $config['characters']['fields'][$name] ?? [];
            if (($old['kind'] ?? '') === 'column' && ($current['kind'] ?? 'column') === 'column' && ! empty($current['column'])) {
                $columns[$old['column']] = $current['column'];
            }
        }
        $table = $config['characters']['table'];
        $oldTable = $original['characters']['table'] ?? $table;
        foreach ($config['filters'] as &$filter) {
            if (($filter['table'] ?? $oldTable) === $oldTable) {
                if (isset($filter['table'])) {
                    $filter['table'] = $table;
                }
            } elseif (isset($filter['link']['local_column'], $columns[$filter['link']['local_column']])) {
                $filter['link']['local_column'] = $columns[$filter['link']['local_column']];
            }
        }
        unset($filter);
        $clanSources = array_filter([$config['features']['castles']['relation'] ?? null, $config['features']['castles']['leader_relation'] ?? null]);
        foreach ($config['relations'] as $name => &$relation) {
            $old = $original['relations'][$name] ?? [];
            if (! in_array($name, $clanSources, true) && ($old['local_column'] ?? null) === $relation['local_column']) {
                $relation['local_column'] = $columns[$relation['local_column']] ?? $relation['local_column'];
            }
            if (($old['table'] ?? null) === $oldTable && in_array($relation['table'], [$table, $oldTable], true)) {
                $relation['table'] = $table;
                if (($old['foreign_column'] ?? null) === $relation['foreign_column']) {
                    $relation['foreign_column'] = $columns[$relation['foreign_column']] ?? $relation['foreign_column'];
                }
            }
        }
        unset($relation);
        foreach ($config['rankings'] as &$ranking) {
            if (isset($columns[$ranking['secondary'] ?? ''])) {
                $ranking['secondary'] = $columns[$ranking['secondary']];
            }
        }
        unset($ranking);

        return $config;
    }

    /** @param array<string,mixed> $rules */
    private function column(array &$rules, string $table, string $column, bool $required = true, bool $optional = false): void
    {
        $rules[$table] ??= ['table' => $table, 'columns' => [], 'required' => $required];
        $rules[$table]['required'] = $rules[$table]['required'] || $required;
        if (! $optional) {
            $rules[$table]['columns'] = array_values(array_unique([...$rules[$table]['columns'], $column]));
            if (isset($rules[$table]['optional_columns'])) {
                $rules[$table]['optional_columns'] = array_values(array_diff($rules[$table]['optional_columns'], [$column]));
            }
        } elseif (! in_array($column, $rules[$table]['columns'], true)) {
            $rules[$table]['optional_columns'] = array_values(array_unique([...($rules[$table]['optional_columns'] ?? []), $column]));
        }
    }

    /** @param array<string,mixed> $config */
    private function wasOptional(array $config, string $table, string $column): bool
    {
        if ($column === '') {
            return false;
        }
        foreach ($config['requirements'] ?? [] as $requirement) {
            if ($requirement['table'] === $table) {
                return ! in_array($column, $requirement['columns'], true)
                    && ! in_array($column, $requirement['any_columns'] ?? [], true);
            }
        }

        return false;
    }
}
