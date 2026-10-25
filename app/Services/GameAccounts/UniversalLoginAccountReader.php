<?php

namespace App\Services\GameAccounts;

use App\Support\GameAccounts\ExternalGameAccountState;
use App\Support\GameAccounts\PreparedGameAccount;
use App\Support\UniversalDrivers\ProfileValidationException;
use App\Support\UniversalDrivers\ProfileValidator;
use Illuminate\Database\Connection;

/**
 * Experimental, read-only LoginServer adapter. Production gateways and resolvers
 * continue to use the legacy drivers until the later migration iteration.
 * Identifiers come only from a validated profile; input values are query-bound.
 */
final class UniversalLoginAccountReader
{
    public function __construct(private readonly ProfileValidator $validator = new ProfileValidator) {}

    /**
     * Explain which schema requirements are present, without running a data query.
     * A missing optional column disables only that piece of account metadata.
     *
     * @param  array<string,mixed>  $profile
     * @return array{missing_required:list<string>,missing_optional:list<string>}
     */
    public function diagnose(Connection $database, array $profile): array
    {
        $config = $this->configuration($database, $profile);
        $requirements = $config['requirements'];
        $schema = $database->getSchemaBuilder();
        $missingRequired = [];
        $missingOptional = [];

        foreach ($requirements as $requirement) {
            $table = $requirement['table'];
            if (! $schema->hasTable($table)) {
                if ($requirement['required']) {
                    $missingRequired[] = 'table:'.$table;
                } else {
                    $missingOptional[] = 'table:'.$table;
                }

                continue;
            }
            $columns = $schema->getColumnListing($table);
            foreach ($requirement['columns'] as $column) {
                if (! in_array($column, $columns, true)) {
                    if ($requirement['required']) {
                        $missingRequired[] = $table.'.'.$column;
                    } else {
                        $missingOptional[] = $table.'.'.$column;
                    }
                }
            }
            foreach ($requirement['optional_columns'] ?? [] as $column) {
                if (! in_array($column, $columns, true)) {
                    $missingOptional[] = $table.'.'.$column;
                }
            }
            // An any_columns group is satisfiable if at least one column exists.
            $alternatives = $requirement['any_columns'] ?? [];
            if ($alternatives !== [] && array_intersect($alternatives, $columns) === []) {
                if ($requirement['required']) {
                    $missingRequired[] = $table.'.{'.implode(',', $alternatives).'}';
                } else {
                    $missingOptional[] = $table.'.{'.implode(',', $alternatives).'}';
                }
            }
        }

        return ['missing_required' => $missingRequired, 'missing_optional' => $missingOptional];
    }

    /**
     * @param  array<string,mixed>  $profile
     * @return array{login:string,created_at:string|null,last_active:int,status:string}|null
     */
    public function summary(Connection $database, array $profile, string $login): ?array
    {
        $config = $this->configuration($database, $profile, 'account_lookup');
        $fields = $config['accounts']['fields'];
        $table = $config['accounts']['table'];
        $available = $this->availableColumns($database, $table, [$fields['login']]);
        $selected = $this->selected($fields, $available, ['login', 'created_at', 'last_active', 'access_level']);
        $account = $database->table($table)->where($fields['login'], $login)->first($selected);
        if ($account === null) {
            return null;
        }

        $created = $this->value($account, $fields, $available, 'created_at');
        $activity = $this->value($account, $fields, $available, 'last_active');
        $access = $this->value($account, $fields, $available, 'access_level');

        return [
            'login' => (string) $this->value($account, $fields, $available, 'login'),
            'created_at' => is_scalar($created) ? (string) $created : null,
            'last_active' => is_numeric($activity) ? (int) $activity : 0,
            // An unavailable access-level field must not silently claim "active".
            'status' => ! is_string($fields['access_level'] ?? null) || ! in_array($fields['access_level'], $available, true)
                ? 'unknown' : (is_numeric($access) && (int) $access < 0 ? 'blocked' : 'active'),
        ];
    }

    /**
     * Compare a persisted account with the immutable prepared credential. No
     * new password hashes are generated here, and nothing is written to the DB.
     *
     * @param  array<string,mixed>  $profile
     */
    public function inspect(Connection $database, array $profile, PreparedGameAccount $account): ExternalGameAccountState
    {
        $config = $this->configuration($database, $profile, 'account_lookup');
        $fields = $config['accounts']['fields'];
        $table = $config['accounts']['table'];
        $password = $fields['password'] ?? null;
        if (! is_string($password)) {
            throw new ProfileValidationException('Credential comparison requires an account password mapping.');
        }
        $available = $this->availableColumns($database, $table, [$fields['login'], $password]);
        $selected = $this->selected($fields, $available, ['login', 'password', 'email']);
        $stored = $database->table($table)->where($fields['login'], $account->login)->first($selected);
        if ($stored === null) {
            return ExternalGameAccountState::Missing;
        }
        if (! hash_equals($account->credential, (string) $this->value($stored, $fields, $available, 'password'))) {
            return ExternalGameAccountState::Conflict;
        }
        // When an email mapping is configured, it is part of account identity.
        // Missing email columns must fail closed instead of linking the wrong user.
        if (is_string($fields['email'] ?? null)) {
            $email = $this->value($stored, $fields, $available, 'email');
            if (! is_scalar($email) || ! hash_equals($account->email, mb_strtolower(trim((string) $email), 'UTF-8'))) {
                return ExternalGameAccountState::Conflict;
            }
        }

        return ExternalGameAccountState::Matching;
    }

    /** @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function configuration(Connection $database, array $profile, ?string $capability = null): array
    {
        $this->validator->validate($profile);
        if ($profile['type'] !== 'login' || $profile['config']['database']['dialect'] !== 'mysql' || $database->getDriverName() !== 'mysql') {
            throw new ProfileValidationException('The selected profile requires a MySQL LoginServer connection.');
        }
        if ($capability !== null && ! in_array($capability, $profile['config']['capabilities'], true)) {
            throw new ProfileValidationException('The profile does not support account lookup.');
        }

        return $profile['config'];
    }

    /** @param list<string> $required
     * @return list<string>
     */
    private function availableColumns(Connection $database, string $table, array $required): array
    {
        $schema = $database->getSchemaBuilder();
        if (! $schema->hasTable($table)) {
            throw new ProfileValidationException('Required LoginServer account table is missing: '.$table);
        }
        $available = $schema->getColumnListing($table);
        foreach ($required as $column) {
            if (! in_array($column, $available, true)) {
                throw new ProfileValidationException('Required LoginServer account column is missing: '.$table.'.'.$column);
            }
        }

        return $available;
    }

    /** @param array<string,string|null> $fields
     * @param  list<string>  $available
     * @param  list<string>  $names
     * @return list<string>
     */
    private function selected(array $fields, array $available, array $names): array
    {
        $columns = [];
        foreach ($names as $name) {
            $column = $fields[$name] ?? null;
            if (is_string($column) && in_array($column, $available, true)) {
                $columns[] = $column;
            }
        }

        return array_values(array_unique($columns));
    }

    /** @param array<string,string|null> $fields
     * @param  list<string>  $available
     */
    private function value(object $record, array $fields, array $available, string $name): mixed
    {
        $column = $fields[$name] ?? null;

        return is_string($column) && in_array($column, $available, true) ? ($record->{$column} ?? null) : null;
    }
}
