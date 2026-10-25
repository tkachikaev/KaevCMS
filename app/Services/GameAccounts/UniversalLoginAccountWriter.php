<?php

namespace App\Services\GameAccounts;

use App\Support\GameAccounts\PreparedGameAccount;
use App\Support\UniversalDrivers\ProfileValidationException;
use App\Support\UniversalDrivers\ProfileValidator;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Experimental MySQL LoginServer write adapter. It does not select connections,
 * grant account ownership or bypass the existing reservation/recovery service.
 * Production resolvers do not call it until an explicit later migration.
 *
 * Callers must persist the prepared credential before an external CREATE, and
 * must retain both hashes for recovery of an ambiguous password update.
 */
final class UniversalLoginAccountWriter
{
    public function __construct(private readonly ProfileValidator $validator = new ProfileValidator) {}

    /** @param array<string,mixed> $profile */
    public function preparePassword(array $profile, string $password): string
    {
        $config = $this->configuration($profile, 'password_change', checkCapability: false);
        $passwordConfig = $config['password'];
        $hash = match ($passwordConfig['scheme']) {
            'sha1_base64' => base64_encode(sha1($password, true)),
            'bcrypt_2a' => $this->bcrypt($password, $passwordConfig['cost']),
            default => throw new ProfileValidationException('Unsupported game password scheme.'),
        };

        if (! $this->acceptsCredential($profile, $hash)) {
            throw new RuntimeException('Failed to prepare the selected game password format.');
        }

        return $hash;
    }

    /** @param array<string,mixed> $profile */
    public function acceptsCredential(array $profile, string $credential): bool
    {
        $config = $this->configuration($profile, 'password_change', checkCapability: false);
        $password = $config['password'];
        if ($password['scheme'] === 'sha1_base64') {
            $decoded = base64_decode($credential, true);

            return is_string($decoded) && strlen($decoded) === 20 && base64_encode($decoded) === $credential;
        }
        if ($password['scheme'] === 'bcrypt_2a') {
            return preg_match('/\A\$2a\$(\d\d)\$[.\/A-Za-z0-9]{53}\z/D', $credential, $matches) === 1
                && (int) $matches[1] === $password['cost'];
        }

        return false;
    }

    /**
     * A duplicate-key exception is intentionally not swallowed: the existing
     * GameAccountGateway translates it and performs recovery using inspect().
     * Never perform a pre-insert 'exists then insert' without a unique index.
     *
     * @param array<string,mixed> $profile
     */
    public function create(Connection $database, array $profile, PreparedGameAccount $account): void
    {
        $config = $this->databaseConfig($database, $profile, 'account_creation');
        if (! $this->acceptsCredential($profile, $account->credential)) {
            throw new ProfileValidationException('Prepared account password is incompatible with the selected driver.');
        }
        if ($account->login === '' || $account->login !== trim($account->login)
            || preg_match('/\A[A-Za-z0-9]+\z/D', $account->login) !== 1) {
            throw new ProfileValidationException('Invalid game account login.');
        }
        $table = $config['accounts']['table'];
        $loginColumn = $config['accounts']['fields']['login'];
        $mapping = $config['accounts']['create']['columns'];
        $fixed = $config['accounts']['create']['fixed_values'] ?? [];
        $this->requireWritableSchema($database, $table, $loginColumn, array_merge(array_keys($mapping), array_keys($fixed)));

        $values = [];
        foreach ($mapping as $column => $source) {
            $values[$column] = match ($source) {
                'login' => $account->login,
                'credential' => $account->credential,
                'email' => $account->email,
            };
        }
        foreach ($fixed as $column => $constant) {
            $values[$column] = $constant;
        }
        $database->table($table)->insert($values);
    }

    /**
     * Compare-and-set with a PREPARED, immutable target hash, rather than a
     * newly salted BCrypt hash on every retry. True means changed/already
     * applied; false means absent. A conflicting stored hash fails closed.
     * On an unknown network outcome retry only with the SAME prepared hash.
     *
     * @param array<string,mixed> $profile
     */
    public function changePreparedPassword(
        Connection $database,
        array $profile,
        string $login,
        string $expectedCredential,
        string $replacementCredential,
    ): bool {
        $config = $this->databaseConfig($database, $profile, 'password_change');
        if ($login === '' || $login !== trim($login)
            || preg_match('/\A[A-Za-z0-9]+\z/D', $login) !== 1) {
            throw new ProfileValidationException('Invalid game account login.');
        }
        if (! $this->acceptsCredential($profile, $replacementCredential)) {
            throw new ProfileValidationException('Replacement game password has an incompatible format.');
        }
        $table = $config['accounts']['table'];
        $fields = $config['accounts']['fields'];
        $loginColumn = $fields['login'];
        $passwordColumn = $fields['password'];
        $this->requireWritableSchema($database, $table, $loginColumn, [$loginColumn, $passwordColumn]);

        // First inspect: never change a password if the expected proof has
        // already been replaced by a third party.
        $stored = $database->table($table)->where($loginColumn, $login)->first([$passwordColumn]);
        if ($stored === null) {
            return false;
        }
        if (hash_equals($replacementCredential, (string) $stored->{$passwordColumn})) {
            return true;
        }
        if (! hash_equals($expectedCredential, (string) $stored->{$passwordColumn})) {
            throw new RuntimeException('Game password differs from the expected credential.');
        }
        $affected = $database->table($table)
            ->where($loginColumn, $login)
            ->where($passwordColumn, $expectedCredential)
            ->update([$passwordColumn => $replacementCredential]);
        if ($affected === 1) {
            return true;
        }
        // In the window after a concurrent update, only an identical target
        // counts as successful. An unrelated update is never overwritten.
        $current = $database->table($table)->where($loginColumn, $login)->first([$passwordColumn]);
        if ($current === null) {
            return false;
        }
        if (hash_equals($replacementCredential, (string) $current->{$passwordColumn})) {
            return true;
        }
        throw new RuntimeException('Game password changed concurrently.');
    }

    /** @param array<string,mixed> $profile
     *  @return array<string,mixed>
     */
    private function configuration(array $profile, string $capability, bool $checkCapability = true): array
    {
        $this->validator->validate($profile);
        if ($profile['type'] !== 'login' || $profile['config']['database']['dialect'] !== 'mysql') {
            throw new ProfileValidationException('Only a MySQL LoginServer profile is supported for game account writes.');
        }
        $config = $profile['config'];
        if ($checkCapability && ! in_array($capability, $config['capabilities'], true)) {
            throw new ProfileValidationException('The LoginServer profile does not support the requested write operation.');
        }
        if (! isset($config['password'])) {
            throw new ProfileValidationException('The LoginServer profile has no password scheme.');
        }

        return $config;
    }

    /** @param array<string,mixed> $profile
     *  @return array<string,mixed>
     */
    private function databaseConfig(Connection $database, array $profile, string $capability): array
    {
        // Reject invalid config BEFORE even inspecting the external connection.
        $config = $this->configuration($profile, $capability);
        if ($database->getDriverName() !== 'mysql') {
            throw new ProfileValidationException('Account writes require an existing MySQL connection.');
        }

        return $config;
    }

    /** @param list<string> $writeColumns */
    private function requireWritableSchema(Connection $database, string $table, string $loginColumn, array $writeColumns): void
    {
        $schema = $database->getSchemaBuilder();
        if (! $schema->hasTable($table)) {
            throw new ProfileValidationException('Required LoginServer account table is missing.');
        }
        $available = $schema->getColumnListing($table);
        foreach ($writeColumns as $column) {
            if (! in_array($column, $available, true)) {
                throw new ProfileValidationException('Required LoginServer write column is missing: '.$table.'.'.$column);
            }
        }
        // Without a unique LOGIN index an update may alter several game
        // accounts, while an INSERT would not be protected against races.
        $uniqueLogin = false;
        foreach ($schema->getIndexes($table) as $index) {
            if (($index['unique'] ?? false) && ($index['columns'] ?? []) === [$loginColumn]) {
                $uniqueLogin = true;
                break;
            }
        }
        if (! $uniqueLogin) {
            throw new ProfileValidationException('LoginServer writes require a unique login index.');
        }
    }

    private function bcrypt(string $password, int $cost): string
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
        if (! is_string($hash) || ! str_starts_with($hash, '$2y$') || strlen($hash) !== 60) {
            throw new RuntimeException('Unable to prepare game BCrypt credential.');
        }

        return '$2a$'.substr($hash, 4);
    }
}
