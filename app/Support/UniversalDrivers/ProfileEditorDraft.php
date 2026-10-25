<?php

namespace App\Support\UniversalDrivers;

/**
 * Presentation-only conversions for the structured offline driver editor.
 * Only the profile validator may authorize a profile for persistence.
 */
final class ProfileEditorDraft
{
    /** @return array<string,mixed> */
    public function blank(string $type): array
    {
        $config = [
            'database' => ['dialect' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
            'requirements' => [['table' => $type === 'login' ? 'accounts' : 'characters', 'columns' => $type === 'login' ? ['login', 'password'] : ['charId', 'char_name', 'account_name'], 'required' => true]],
            'capabilities' => $type === 'login' ? ['account_lookup'] : ['characters'],
        ];
        if ($type === 'login') {
            $config['accounts'] = [
                'table' => 'accounts',
                'fields' => ['login' => 'login', 'password' => 'password'],
                'ownership' => ['identity' => 'login', 'column' => 'login'],
                'create' => ['columns' => ['login' => 'login', 'password' => 'credential']],
            ];
            $config['password'] = ['scheme' => 'sha1_base64'];
        } else {
            $config += [
                'characters' => ['table' => 'characters', 'owner' => ['identity' => 'login', 'column' => 'account_name'], 'fields' => [
                    'id' => ['kind' => 'column', 'column' => 'charId'],
                    'name' => ['kind' => 'column', 'column' => 'char_name'],
                ]],
                'relations' => [], 'filters' => [], 'rankings' => [], 'features' => [],
            ];
        }

        return ['id' => '', 'name' => '', 'type' => $type, 'format_version' => ProfileFormat::CURRENT_VERSION, 'config' => $config];
    }

    /** @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    public function clean(array $profile): array
    {
        if (! isset($profile['config']) || ! is_array($profile['config']) ||
            ! isset($profile['config']['requirements']) || ! is_array($profile['config']['requirements'])) {
            throw new ProfileValidationException('Malformed editor configuration.');
        }
        if ($profile['type'] === 'login') {
            if (! isset($profile['config']['accounts']['fields'], $profile['config']['password']) ||
                ! is_array($profile['config']['accounts']['fields']) || ! is_array($profile['config']['password'])) {
                throw new ProfileValidationException('Malformed LoginServer editor fields.');
            }
        } elseif ($profile['type'] === 'game') {
            foreach (['characters', 'relations', 'filters', 'rankings', 'features'] as $required) {
                if (! isset($profile['config'][$required]) || ! is_array($profile['config'][$required])) {
                    throw new ProfileValidationException('Malformed GameServer editor fields.');
                }
            }
            if (! is_array($profile['config']['characters']['fields'] ?? null)) {
                throw new ProfileValidationException('Malformed character field mappings.');
            }
        } else {
            throw new ProfileValidationException('Invalid driver type.');
        }
        $config = &$profile['config'];
        foreach (['charset', 'collation'] as $field) {
            $config['database'][$field] = trim((string) ($config['database'][$field] ?? ''));
        }
        foreach ($config['requirements'] as &$requirement) {
            foreach (['columns', 'optional_columns', 'any_columns'] as $key) {
                $requirement[$key] = array_values(array_filter(array_map('trim', $requirement[$key] ?? []), static fn ($value) => $value !== ''));
                if ($key !== 'columns' && $requirement[$key] === []) {
                    unset($requirement[$key]);
                }
            }
            $requirement['required'] = (bool) $requirement['required'];
        }
        unset($requirement);
        if ($profile['type'] === 'login') {
            $fields = &$config['accounts']['fields'];
            foreach ($fields as $key => &$column) {
                if ($column !== null && ! is_string($column)) {
                    throw new ProfileValidationException('Invalid account field mapping.');
                }
                if ($column !== null) {
                    $column = trim($column);
                }
                if ($column === '') {
                    $column = null;
                }
            }
            unset($column);
            // Only trusted field sources may be written during registration.
            $columns = [];
            foreach (['login' => 'login', 'password' => 'credential', 'email' => 'email'] as $key => $source) {
                if (isset($fields[$key])) {
                    $columns[$fields[$key]] = $source;
                }
            }
            $config['accounts']['create']['columns'] = $columns;
            if (empty($config['accounts']['create']['fixed_values'])) {
                unset($config['accounts']['create']['fixed_values']);
            }
            if ($config['password']['scheme'] === 'bcrypt_2a') {
                $config['password']['cost'] = (int) ($config['password']['cost'] ?? 10);
            } else {
                unset($config['password']['cost']);
            }
        } else {
            foreach ($config['characters']['fields'] as &$def) {
                $kind = $def['kind'] ?? 'column';
                $allowed = match ($kind) {
                    'column' => ['kind', 'column'],
                    'related' => ['kind', 'relation', 'column'],
                    'choice' => ['kind', 'columns', 'preference'],
                    'eav' => ['kind', 'relation', 'key_column', 'value_column', 'key_value'],
                    'operator' => ['kind', 'operator'],
                    default => ['kind'],
                };
                $def = array_intersect_key($def, array_flip($allowed));
                if ($kind === 'choice') {
                    $def['columns'] = array_values(array_filter(array_map('trim', $def['columns'] ?? [])));
                }
            }
            unset($def);
            foreach ($config['filters'] as &$filter) {
                if ($filter['op'] === 'zero_or_null') {
                    unset($filter['value']);
                } else {
                    $filter['value'] ??= '';
                }
            }
            unset($filter);
            foreach ($config['features'] as $key => &$feature) {
                if ($key === 'castles') {
                    if (($feature['name_source'] ?? 'catalog') === 'catalog') {
                        unset($feature['name_column']);
                    }
                }
                foreach ($feature as $field => $value) {
                    if ($value === '' || $value === null) {
                        unset($feature[$field]);
                    }
                }
            }
            unset($feature);
        }
        return $profile;
    }
}
