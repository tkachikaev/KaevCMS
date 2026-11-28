<?php

namespace App\Services\Servers;

use App\Support\UniversalDrivers\GameCharacterFilters;
use App\Support\UniversalDrivers\ProfileFormat;
use App\Support\UniversalDrivers\ProfileSchema;
use Illuminate\Database\Connection;

/**
 * Read-only checks for schema properties that a table/column presence probe cannot prove.
 * No external table is created, updated or locked. These are structural guarantees,
 * not proof that a live game operation will succeed on every possible dataset.
 */
final class UniversalProfileSchemaProbe
{
    /**
     * @param  array<string,mixed>  $profile
     * @return list<array{key:string,replace:array<string,int|string>}>
     */
    public function problems(Connection $connection, array $profile): array
    {
        $config = $profile['config'];
        $schema = $connection->getSchemaBuilder();
        $problems = [];

        if ($profile['type'] === 'login') {
            $table = $config['accounts']['table'];
            if ($schema->hasTable($table) === false) {
                return [['key' => 'drivers.schema_missing_table', 'replace' => ['table' => $table]]];
            }
            $fields = $config['accounts']['fields'];
            $columns = $schema->getColumnListing($table);
            foreach (['login', ...($this->writable($config) ? ['password'] : [])] as $field) {
                if (is_string($fields[$field] ?? null) === false || in_array($fields[$field], $columns, true) === false) {
                    $problems[] = ['key' => 'drivers.schema_missing_account_field', 'replace' => ['field' => $field]];
                }
            }
            $loginColumn = $fields['login'];
            if (in_array($loginColumn, $columns, true) && ! ProfileSchema::identityColumnMatches($connection, $table, $loginColumn, 'login')) {
                $problems[] = ['key' => 'drivers.schema_identity_type', 'replace' => ['column' => $table.'.'.$loginColumn]];
            }
            if ($this->writable($config)) {
                if (ProfileSchema::hasUniqueColumn($connection, $table, $loginColumn) === false) {
                    $problems[] = ['key' => 'drivers.schema_unique_login', 'replace' => ['column' => $table.'.'.$loginColumn]];
                }
                $passwordColumn = $fields['password'] ?? null;
                if (is_string($passwordColumn) && in_array($passwordColumn, $columns, true)) {
                    $problem = ProfileSchema::passwordColumnProblem(
                        $connection, $table, $passwordColumn, $this->credentialLength($config['password']['scheme'] ?? ''),
                    );
                    if ($problem !== null) {
                        $problems[] = $problem;
                    }
                }
            }
            if (in_array('account_creation', $config['capabilities'], true)) {
                foreach (array_unique(array_merge(array_keys($config['accounts']['create']['columns'] ?? []), array_keys($config['accounts']['create']['fixed_values'] ?? []))) as $column) {
                    if (in_array($column, $columns, true) === false) {
                        $problems[] = ['key' => 'drivers.schema_missing_column', 'replace' => ['column' => $table.'.'.$column]];
                    }
                }
            }

            return $problems;
        }

        $table = $config['characters']['table'];
        if ($schema->hasTable($table) === false) {
            return [['key' => 'drivers.schema_missing_table', 'replace' => ['table' => $table]]];
        }
        $columns = $schema->getColumnListing($table);
        foreach ([$config['characters']['owner']['column'], $config['characters']['fields']['id']['column'], $config['characters']['fields']['name']['column']] as $column) {
            if (in_array($column, $columns, true) === false) {
                $problems[] = ['key' => 'drivers.schema_missing_column', 'replace' => ['column' => $table.'.'.$column]];
            }
        }
        $owner = $config['characters']['owner'];
        if (in_array($owner['column'], $columns, true) && ! ProfileSchema::identityColumnMatches($connection, $table, $owner['column'], $owner['identity'])) {
            $problems[] = ['key' => 'drivers.schema_identity_type', 'replace' => ['column' => $table.'.'.$owner['column']]];
        }
        foreach (GameCharacterFilters::requirements($config) as $filterTable => $filterColumns) {
            if ($schema->hasTable($filterTable) === false) {
                $problems[] = ['key' => 'drivers.schema_missing_table', 'replace' => ['table' => $filterTable]];

                continue;
            }
            $available = $schema->getColumnListing($filterTable);
            foreach (array_unique($filterColumns) as $column) {
                if (in_array($column, $available, true) === false) {
                    $problems[] = ['key' => 'drivers.schema_missing_column', 'replace' => ['column' => $filterTable.'.'.$column]];
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
        if (isset(ProfileFormat::BASE64_PASSWORD_SCHEMES[$scheme])) {
            $bytes = ProfileFormat::BASE64_PASSWORD_SCHEMES[$scheme][1];

            return 4 * intdiv($bytes + 2, 3);
        }

        return match ($scheme) {
            'bcrypt_2a' => 60,
            default => ProfileFormat::HEX_PASSWORD_SCHEMES[$scheme][1] ?? 0,
        };
    }
}
