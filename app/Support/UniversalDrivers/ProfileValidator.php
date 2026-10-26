<?php

namespace App\Support\UniversalDrivers;

use JsonException;

/**
 * Fail-closed structural validation. Identifiers here are DATA, not SQL fragments.
 * Later query adapters must use query bindings and trusted grammar/identifiers.
 */
final class ProfileValidator
{
    public function __construct(private readonly ProfileFormatMigrator $migrator = new ProfileFormatMigrator) {}

    /** @return array<string, mixed> */
    public function fromJson(string $json): array
    {
        if ($json === '' || strlen($json) > ProfileFormat::MAX_BYTES) {
            throw new ProfileValidationException('Driver JSON is empty or exceeds 256 KiB.');
        }

        try {
            $profile = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProfileValidationException('Invalid driver JSON structure.', previous: $exception);
        }

        if (! is_array($profile) || array_is_list($profile)) {
            throw new ProfileValidationException('Driver JSON root must be an object.');
        }

        /** @var array<string, mixed> $profile */
        $profile = $this->migrator->toCurrent($profile);
        $this->validate($profile);

        return $profile;
    }

    /** @param array<string, mixed> $profile */
    public function validate(array $profile): void
    {
        $this->keys($profile, ['id', 'name', 'type', 'format_version', 'config'], ['id', 'name', 'type', 'format_version', 'config'], 'profile');
        $this->string($profile['id'], 'id', 80);
        if (preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/D', $profile['id']) !== 1) {
            throw new ProfileValidationException('Invalid driver profile id.');
        }
        $this->string($profile['name'], 'name', 100);
        if (! in_array($profile['type'], ['login', 'game'], true)) {
            throw new ProfileValidationException('Driver type must be login or game.');
        }
        if ($profile['format_version'] !== ProfileFormat::CURRENT_VERSION) {
            throw new ProfileValidationException('Unsupported driver profile format.');
        }
        $config = $this->object($profile['config'], 'config');
        $this->keys($config, ['database', 'requirements', 'capabilities', 'accounts', 'password', 'characters', 'relations', 'filters', 'rankings', 'features', 'extensions'], ['database', 'requirements', 'capabilities'], 'config');
        if (isset($config['extensions'])) {
            $this->extensions($config['extensions']);
        }
        $this->database($this->object($config['database'], 'database'));
        $this->requirements($config['requirements']);
        $this->capabilities($config['capabilities'], $profile['type']);
        if ($profile['type'] === 'login') {
            $this->keys($config, ['database', 'requirements', 'capabilities', 'accounts', 'password', 'extensions'], ['database', 'requirements', 'capabilities', 'accounts'], 'login config');
            $this->accounts($this->object($config['accounts'], 'accounts'), $config['capabilities'], $config['requirements']);
            $loginFields = $config['accounts']['fields'];
            $primaryColumns = [$loginFields['login']];
            if (in_array('account_creation', $config['capabilities'], true) || in_array('password_change', $config['capabilities'], true)) {
                $primaryColumns[] = $loginFields['password'];
            }
            $this->requirePrimaryTable($config['accounts']['table'], $config['requirements'], $primaryColumns);
            if (in_array('account_creation', $config['capabilities'], true) || in_array('password_change', $config['capabilities'], true)) {
                if (! isset($config['password'])) {
                    throw new ProfileValidationException('Account creation/change requires a supported password scheme.');
                }
            }
            if (isset($config['password'])) {
                $this->password($this->object($config['password'], 'password'));
            }
        } else {
            $this->keys($config, ['database', 'requirements', 'capabilities', 'characters', 'relations', 'filters', 'rankings', 'features', 'extensions'], ['database', 'requirements', 'capabilities', 'characters', 'relations', 'filters', 'rankings', 'features'], 'game config');
            $relations = $this->relations($this->object($config['relations'], 'relations'));
            $this->characters($this->object($config['characters'], 'characters'), $relations);
            // Validate filter structure before using it to verify the schema contract.
            $this->validateGameFilters($config);
            $characters = $config['characters'];
            $baseColumns = [
                $characters['owner']['column'],
                $characters['fields']['id']['column'],
                $characters['fields']['name']['column'],
            ];
            foreach (GameCharacterFilters::requirements($config) as $filterTable => $filterColumns) {
                $this->requirePrimaryTable($filterTable, $config['requirements'], $filterColumns);
            }
            $this->requirePrimaryTable($characters['table'], $config['requirements'], $baseColumns);
            $this->rankings($this->object($config['rankings'], 'rankings'), $this->object($config['characters']['fields'], 'characters.fields'));
            $this->features($this->object($config['features'], 'features'), $relations);
            $fields = $config['characters']['fields'];
            $capabilities = $config['capabilities'];
            $requiredFieldForCapability = [
                'level' => 'level', 'pvp' => 'pvp_kills', 'pk' => 'pk_kills',
                'play_time' => 'play_time_seconds',
            ];
            foreach ($requiredFieldForCapability as $capability => $field) {
                if (in_array($capability, $capabilities, true) && (! isset($fields[$field]) || ! isset($config['rankings'][$capability]))) {
                    throw new ProfileValidationException("Capability $capability requires field and ranking mappings.");
                }
            }
            foreach (['heroes', 'castles'] as $capability) {
                if (in_array($capability, $capabilities, true) && ! isset($config['features'][$capability])) {
                    throw new ProfileValidationException("Capability $capability requires feature mapping.");
                }
            }
            if (in_array('character_rescue', $capabilities, true)) {
                foreach (['x', 'y', 'z'] as $axis) {
                    if (! isset($fields[$axis])) {
                        throw new ProfileValidationException('Character rescue requires x/y/z field mappings.');
                    }
                }
            }
        }
    }

    /**
     * Answers that modules asked the driver for (config.extensions.<module>.<key>.<field>). Only the
     * shape is checked here, so a profile can be imported before its module is installed; the editor
     * checks the values against the module's own declaration.
     */
    private function extensions(mixed $extensions): void
    {
        $extensions = $this->object($extensions, 'extensions');
        if (count($extensions) > 16) {
            throw new ProfileValidationException('Too many module extensions.');
        }
        foreach ($extensions as $module => $groups) {
            if (preg_match('/\A[a-z0-9][a-z0-9-]{0,99}\z/D', (string) $module) !== 1) {
                throw new ProfileValidationException('Invalid module identifier in extensions.');
            }
            $groups = $this->object($groups, 'extensions.'.$module);
            if (count($groups) > 16) {
                throw new ProfileValidationException('Too many extensions for one module.');
            }
            foreach ($groups as $key => $values) {
                if (preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D', (string) $key) !== 1) {
                    throw new ProfileValidationException('Invalid extension key.');
                }
                $values = $this->object($values, 'extensions.'.$module.'.'.$key);
                if (count($values) > 24) {
                    throw new ProfileValidationException('Too many extension values.');
                }
                foreach ($values as $name => $value) {
                    if (preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D', (string) $name) !== 1) {
                        throw new ProfileValidationException('Invalid extension value name.');
                    }
                    if (is_string($value) ? strlen($value) > 128 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1 : ! is_int($value) && ! is_bool($value)) {
                        throw new ProfileValidationException('Unsupported extension value at '.$module.'.'.$key.'.'.$name.'.');
                    }
                }
            }
        }
    }

    /**
     * A schema preflight is only trustworthy when the primary table and every
     * identity/filter column it needs are declared REQUIRED. Otherwise the
     * connection tester can approve a profile that fails on its first query.
     *
     * @param  list<array<string,mixed>>  $requirements
     * @param  list<string>  $requiredColumns
     */
    private function requirePrimaryTable(string $table, array $requirements, array $requiredColumns): void
    {
        foreach ($requirements as $requirement) {
            if ($requirement['table'] !== $table) {
                continue;
            }
            if ($requirement['required'] !== true) {
                throw new ProfileValidationException('The primary table must be a required schema table.');
            }
            foreach (array_unique($requiredColumns) as $column) {
                if (! in_array($column, $requirement['columns'], true)) {
                    throw new ProfileValidationException('Required primary table column is not declared: '.$table.'.'.$column);
                }
            }

            return;
        }
        throw new ProfileValidationException('The primary table must appear in schema requirements.');
    }

    /** @param array<string, mixed> $database */
    private function database(array $database): void
    {
        $this->keys($database, ['dialect', 'charset', 'collation'], ['dialect', 'charset', 'collation'], 'database');
        $this->oneOf($database['dialect'], ProfileFormat::PROFILE_DATABASES, 'database.dialect');
        $this->identifier($database['charset'], 'database.charset');
        $this->identifier($database['collation'], 'database.collation');
        // Explicit manual charsets/collations are permitted offline, with a safe
        // lexical relationship; existence is checked only against the actual DB later.
        if (! str_starts_with($database['collation'], $database['charset'].'_')) {
            throw new ProfileValidationException('Charset and collation are incompatible.');
        }
        // A collation is a known MySQL/MariaDB suffix, not free text: a made-up name only
        // breaks the connection later with an unclear error.
        $suffix = substr($database['collation'], strlen($database['charset']) + 1);
        if (preg_match('/\A(?:bin|0900_bin|(?:general|unicode|unicode_520|swedish|danish|german2|polish|czech|russian|turkish|spanish|0900_ai|0900_as)_(?:ci|cs)|general_mysql500_ci)\z/D', $suffix) !== 1) {
            throw new ProfileValidationException('Unsupported database collation.');
        }
    }

    private function requirements(mixed $requirements): void
    {
        $this->list($requirements, 'requirements', 32);
        if ($requirements === []) {
            throw new ProfileValidationException('At least one table requirement is needed.');
        }
        $tables = [];
        foreach ($requirements as $i => $value) {
            $r = $this->object($value, "requirements.$i");
            $this->keys($r, ['table', 'columns', 'any_columns', 'optional_columns', 'required'], ['table', 'columns', 'required'], 'requirement');
            $this->identifier($r['table'], 'requirement.table');
            if (in_array($r['table'], $tables, true)) {
                throw new ProfileValidationException('Duplicate table in schema requirements.');
            }
            $tables[] = $r['table'];
            if (! is_bool($r['required'])) {
                throw new ProfileValidationException('Requirement.required must be boolean.');
            }
            foreach (['columns', 'any_columns', 'optional_columns'] as $key) {
                if (array_key_exists($key, $r)) {
                    $this->identifiers($r[$key], "requirement.$key", 80);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $accounts
     * @param  list<string>  $capabilities
     * @param  array<int|string, mixed>  $requirements
     */
    private function accounts(array $accounts, array $capabilities, array $requirements): void
    {
        $this->keys($accounts, ['table', 'fields', 'ownership', 'create'], ['table', 'fields', 'ownership'], 'accounts');
        $this->identifier($accounts['table'], 'accounts.table');
        $fields = $this->object($accounts['fields'], 'accounts.fields');
        $this->keys($fields, ['login', 'password', 'id', 'email', 'created_at', 'last_active', 'access_level', 'last_server'], ['login'], 'accounts.fields');
        foreach ($fields as $key => $column) {
            if ($column !== null) {
                $this->identifier($column, "accounts.fields.$key");
            }
        }
        $ownership = $this->object($accounts['ownership'], 'accounts.ownership');
        $this->keys($ownership, ['identity', 'column'], ['identity', 'column'], 'ownership');
        $this->oneOf($ownership['identity'], ['login', 'id'], 'ownership.identity');
        $this->identifier($ownership['column'], 'ownership.column');
        if ($ownership['identity'] === 'id' && ! is_string($fields['id'] ?? null)) {
            throw new ProfileValidationException('Account ID ownership requires accounts.fields.id.');
        }
        $identityField = $ownership['identity'] === 'login' ? $fields['login'] : $fields['id'];
        if ($ownership['column'] !== $identityField) {
            throw new ProfileValidationException('Account ownership column must match its declared login/ID field.');
        }
        if (in_array('account_creation', $capabilities, true) && ! isset($accounts['create'])) {
            throw new ProfileValidationException('Account creation requires field mapping.');
        }
        if ((in_array('account_creation', $capabilities, true) || in_array('password_change', $capabilities, true)) && ! is_string($fields['password'] ?? null)) {
            throw new ProfileValidationException('Password writes require accounts.fields.password.');
        }
        if (! isset($accounts['create'])) {
            return;
        }
        $create = $this->object($accounts['create'], 'accounts.create');
        $this->keys($create, ['columns', 'fixed_values'], ['columns'], 'accounts.create');
        $columns = $this->object($create['columns'], 'accounts.create.columns');
        if (count($columns) > 32) {
            throw new ProfileValidationException('Too many account insertion fields.');
        }
        foreach ($columns as $column => $source) {
            $this->identifier($column, 'create column');
            $this->oneOf($source, ['login', 'credential', 'email'], 'create source');
            $expected = match ($source) {
                'login' => $fields['login'],
                'credential' => $fields['password'] ?? null,
                'email' => $fields['email'] ?? null,
                default => throw new ProfileValidationException('Unsupported account insertion source.'),
            };
            if ($expected !== $column) {
                throw new ProfileValidationException('Dynamic account fields must match their declared column mapping.');
            }
        }
        if (in_array('account_creation', $capabilities, true) && ($columns[$fields['login']] ?? null) !== 'login') {
            throw new ProfileValidationException('Account creation must provide its login field.');
        }
        if (in_array('account_creation', $capabilities, true) && ($columns[$fields['password']] ?? null) !== 'credential') {
            throw new ProfileValidationException('Account creation must provide the credential field.');
        }
        if (in_array('account_creation', $capabilities, true) && in_array('email', $columns, true) && ($fields['email'] ?? null) === null) {
            throw new ProfileValidationException('Cannot insert email without an email column.');
        }
        if (isset($create['fixed_values'])) {
            $fixed = $this->object($create['fixed_values'], 'accounts.create.fixed_values');
            if (count($fixed) > 16) {
                throw new ProfileValidationException('Too many fixed account insertion values.');
            }
            // A constant cannot override a trusted dynamic login/password
            // input. It must also refer to a declared schema column.
            $declaredColumns = [];
            foreach ($requirements as $requirement) {
                if ($requirement['table'] === $accounts['table']) {
                    $declaredColumns = array_merge($requirement['columns'], $requirement['optional_columns'] ?? []);
                    break;
                }
            }
            foreach ($fixed as $column => $value) {
                $this->identifier($column, 'fixed value column');
                if (! in_array($column, $declaredColumns, true)) {
                    throw new ProfileValidationException('A fixed account value must map to a declared schema column.');
                }
                $this->scalar($value, 'fixed value');
                if (array_key_exists($column, $columns) || in_array($column, [$fields['login'], $fields['password'] ?? null, $fields['email'] ?? null], true)) {
                    throw new ProfileValidationException('Fixed values cannot overwrite mapped account identity fields.');
                }
                // Privileged account flags must not be set by importable profiles.
                if (preg_match('/(?:access.?level|privilege|is.?admin|is.?gm|authority)/i', $column) === 1 && ! in_array($value, [0, '0', false], true)) {
                    throw new ProfileValidationException('Fixed values cannot grant game account privileges.');
                }
            }
        }
    }

    /** @param array<string, mixed> $password */
    private function password(array $password): void
    {
        $this->keys($password, ['scheme', 'cost'], ['scheme'], 'password');
        $this->oneOf($password['scheme'], ProfileFormat::PASSWORD_SCHEMES, 'password.scheme');
        if ($password['scheme'] === 'bcrypt_2a') {
            if (! isset($password['cost']) || ! is_int($password['cost']) || $password['cost'] < 4 || $password['cost'] > 16) {
                throw new ProfileValidationException('BCrypt cost must be an integer from 4 to 16.');
            }
        } elseif (array_key_exists('cost', $password)) {
            throw new ProfileValidationException('Cost is valid only for BCrypt.');
        }
    }

    /**
     * @param  array<int|string, mixed>  $relations
     * @return list<string>
     */
    private function relations(array $relations): array
    {
        if (count($relations) > 16) {
            throw new ProfileValidationException('Too many game relations.');
        }
        foreach ($relations as $name => $value) {
            $this->identifier($name, 'relation name');
            $r = $this->object($value, "relations.$name");
            $this->keys($r, ['table', 'local_column', 'foreign_column', 'join', 'cardinality'], ['table', 'local_column', 'foreign_column', 'join', 'cardinality'], 'relation');
            foreach (['table', 'local_column', 'foreign_column'] as $key) {
                $this->identifier($r[$key], "relation.$key");
            }
            $this->oneOf($r['join'], ['left', 'inner'], 'relation.join');
            $this->oneOf($r['cardinality'], ['one', 'many'], 'relation.cardinality');
        }

        return array_keys($relations);
    }

    /** @param array<string, mixed> $characters
     * @param  list<string>  $relations
     */
    private function characters(array $characters, array $relations): void
    {
        $this->keys($characters, ['table', 'owner', 'fields'], ['table', 'owner', 'fields'], 'characters');
        $this->identifier($characters['table'], 'characters.table');
        $owner = $this->object($characters['owner'], 'characters.owner');
        $this->keys($owner, ['identity', 'column'], ['identity', 'column'], 'characters.owner');
        $this->oneOf($owner['identity'], ['login', 'id'], 'characters.owner.identity');
        $this->identifier($owner['column'], 'characters.owner.column');
        $fields = $this->object($characters['fields'], 'characters.fields');
        $this->keys($fields, ProfileFormat::FIELD_NAMES, ['id', 'name'], 'characters.fields');
        foreach ($fields as $key => $def) {
            $item = $this->object($def, "characters.fields.$key");
            $this->keys($item, ['kind', 'column', 'relation', 'columns', 'preference', 'operator', 'key_column', 'value_column', 'key_value'], ['kind'], "characters.fields.$key");
            $this->oneOf($item['kind'], ['column', 'related', 'choice', 'eav', 'operator'], "characters.fields.$key.kind");
            $kind = $item['kind'];
            $required = match ($kind) {
                'column' => ['kind', 'column'],
                'related' => ['kind', 'relation', 'column'],
                'choice' => ['kind', 'columns', 'preference'],
                'eav' => ['kind', 'relation', 'key_column', 'value_column', 'key_value'],
                default => ['kind', 'operator'],
            };
            $this->keys($item, $required, $required, "characters.fields.$key ($kind)");
            if (in_array($kind, ['column', 'related'], true)) {
                $this->identifier($item['column'], "$key.column");
            }
            if (in_array($kind, ['related', 'eav'], true)) {
                $this->oneOf($item['relation'], $relations, "$key.relation");
            }
            if ($kind === 'choice') {
                $this->identifiers($item['columns'], "$key.columns", 4);
                $this->oneOf($item['preference'], ['first_present', 'mobius_chronicle'], "$key.preference");
            }
            if ($kind === 'eav') {
                $this->identifier($item['key_column'], "$key.key_column");
                $this->identifier($item['value_column'], "$key.value_column");
                $this->scalar($item['key_value'], "$key.key_value");
            }
            if ($kind === 'operator') {
                $this->oneOf($item['operator'], ['rusacis_hero_active'], "$key.operator");
            }
        }
        if ($fields['id']['kind'] !== 'column' || $fields['name']['kind'] !== 'column') {
            throw new ProfileValidationException('Character id and name must have direct source columns in v1.');
        }
    }

    /** @param array<string,mixed> $config */
    public function validateGameFilters(array $config): void
    {
        $filters = $config['filters'];
        $baseTable = $config['characters']['table'];
        $this->list($filters, 'filters', 16);
        foreach ($filters as $entry) {
            $filter = $this->object($entry, 'filter');
            $this->keys($filter, ['table', 'column', 'op', 'value', 'link'], ['column', 'op'], 'filter');
            $table = $filter['table'] ?? $baseTable;
            if (array_key_exists('table', $filter)) {
                $this->identifier($filter['table'], 'filter.table');
            }
            $this->identifier($table, 'filter.table');
            if ($table !== $baseTable) {
                if (! isset($filter['link'])) {
                    throw new ProfileValidationException('drivers.filter_link_required');
                }
                $link = $this->object($filter['link'], 'filter.link');
                $this->keys($link, ['local_column', 'foreign_column'], ['local_column', 'foreign_column'], 'filter.link');
                foreach (['local_column', 'foreign_column'] as $key) {
                    if ($link[$key] === '') {
                        throw new ProfileValidationException('drivers.filter_link_required');
                    }
                    $this->identifier($link[$key], 'filter.link.'.$key);
                }
            } elseif (array_key_exists('link', $filter)) {
                throw new ProfileValidationException('drivers.filter_link_primary');
            }
            $this->identifier($filter['column'], 'filter.column');
            $this->oneOf($filter['op'], ['eq', 'gt', 'zero_or_null'], 'filter.op');
            if ($filter['op'] === 'zero_or_null' && array_key_exists('value', $filter)) {
                throw new ProfileValidationException('zero_or_null filter does not take a value.');
            }
            if ($filter['op'] !== 'zero_or_null' && ! array_key_exists('value', $filter)) {
                throw new ProfileValidationException('Comparison filter must have a value.');
            }
            if (array_key_exists('value', $filter)) {
                $this->scalar($filter['value'], 'filter.value');
            }
        }
    }

    /** @param array<string, mixed> $rankings
     * @param  array<string, mixed>  $fields
     */
    private function rankings(array $rankings, array $fields): void
    {
        $this->keys($rankings, ['level', 'pvp', 'pk', 'play_time'], [], 'rankings');
        foreach ($rankings as $name => $value) {
            $r = $this->object($value, "ranking.$name");
            $this->keys($r, ['column', 'secondary', 'positive_only'], ['column', 'secondary', 'positive_only'], 'ranking');
            $this->identifier($r['column'], 'ranking.column');
            if ($r['secondary'] !== null) {
                $this->identifier($r['secondary'], 'ranking.secondary');
            }
            if (! is_bool($r['positive_only'])) {
                throw new ProfileValidationException('ranking.positive_only must be boolean.');
            }
        }
    }

    /** @param array<string, mixed> $features
     * @param  list<string>  $relations
     */
    private function features(array $features, array $relations): void
    {
        $this->keys($features, ['heroes', 'castles'], [], 'features');
        foreach ($features as $key => $value) {
            $feature = $this->object($value, "features.$key");
            $allowed = $key === 'heroes' ? ['relation', 'played_column', 'active_column', 'active_value', 'played_value', 'count_column', 'claimed_column'] : ['relation', 'name_source', 'name_column', 'clan_relation', 'leader_relation', 'clan_columns', 'leader_name_column'];
            $required = $key === 'heroes' ? ['relation'] : ['relation', 'name_source'];
            $this->keys($feature, $allowed, $required, "features.$key");
            $this->oneOf($feature['relation'], $relations, "features.$key.relation");
            if ($key === 'heroes') {
                foreach (['played_column', 'active_column', 'count_column', 'claimed_column'] as $column) {
                    if (isset($feature[$column])) {
                        $this->identifier($feature[$column], "features.heroes.$column");
                    }
                }
                foreach (['active_value', 'played_value'] as $valueKey) {
                    if (isset($feature[$valueKey])) {
                        $this->scalar($feature[$valueKey], "features.heroes.$valueKey");
                    }
                }
            } else {
                foreach (['clan_relation', 'leader_relation'] as $keyName) {
                    if (isset($feature[$keyName])) {
                        $this->oneOf($feature[$keyName], $relations, 'features.castles.'.$keyName);
                    }
                }
                if (isset($feature['clan_columns'])) {
                    $mapping = $this->object($feature['clan_columns'], 'features.castles.clan_columns');
                    $this->keys($mapping, ['id', 'name', 'level', 'reputation'], ['id', 'name', 'level', 'reputation'], 'features.castles.clan_columns');
                    foreach ($mapping as $columnName) {
                        $this->identifier($columnName, 'features.castles.clan_columns field');
                    }
                }
                if (isset($feature['leader_name_column'])) {
                    $this->identifier($feature['leader_name_column'], 'features.castles.leader_name_column');
                }
                $this->oneOf($feature['name_source'], ['column', 'catalog'], 'features.castles.name_source');
                if ($feature['name_source'] === 'column') {
                    if (! isset($feature['name_column'])) {
                        throw new ProfileValidationException('Castle name column is required when name_source=column.');
                    }
                    $this->identifier($feature['name_column'], 'features.castles.name_column');
                } elseif (array_key_exists('name_column', $feature)) {
                    throw new ProfileValidationException('Catalog names must not specify an SQL name column.');
                }
            }
        }
    }

    /** @param array<string, mixed> $capabilities */
    private function capabilities(mixed $capabilities, string $type): void
    {
        $this->list($capabilities, 'capabilities', 16);
        $this->identifiers($capabilities, 'capabilities', 16);
        foreach ($capabilities as $capability) {
            $this->oneOf($capability, $type === 'login' ? ProfileFormat::LOGIN_CAPABILITIES : ProfileFormat::GAME_CAPABILITIES, 'capability');
        }
    }

    /** @param array<string, mixed> $value
     * @param  list<string>  $allowed
     * @param  list<string>  $required
     */
    private function keys(array $value, array $allowed, array $required, string $at): void
    {
        foreach ($required as $key) {
            if (! array_key_exists($key, $value)) {
                throw new ProfileValidationException("Missing $at.$key.");
            }
        }
        foreach (array_keys($value) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new ProfileValidationException("Unexpected key $at.$key.");
            }
        }
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $at): array
    {
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw new ProfileValidationException("$at must be an object.");
        }

        return $value;
    }

    private function list(mixed $value, string $at, int $max): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $max) {
            throw new ProfileValidationException("$at must be a list (up to $max items).");
        }
    }

    /** @param list<string> $options */
    private function oneOf(mixed $value, array $options, string $at): void
    {
        if (! is_string($value) || ! in_array($value, $options, true)) {
            throw new ProfileValidationException("Unsupported value for $at.");
        }
    }

    private function identifier(mixed $value, string $at): void
    {
        if (! is_string($value) || strlen($value) > 64 || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $value) !== 1) {
            throw new ProfileValidationException("Invalid identifier at $at.");
        }
    }

    private function string(mixed $value, string $at, int $max): void
    {
        if (! is_string($value) || trim($value) === '' || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ProfileValidationException("Invalid text at $at.");
        }
    }

    private function scalar(mixed $value, string $at): void
    {
        if (! is_string($value) && ! is_int($value) && ! is_bool($value) || is_string($value) && strlen($value) > 128) {
            throw new ProfileValidationException("Unsupported bound value at $at.");
        }
    }

    private function identifiers(mixed $value, string $at, int $max): void
    {
        $this->list($value, $at, $max);
        foreach ($value as $item) {
            $this->identifier($item, $at);
        }
        if (count($value) !== count(array_unique($value))) {
            throw new ProfileValidationException("Duplicate values at $at.");
        }
    }
}
