<?php

namespace App\Services\GameAccounts;

use App\Contracts\LoginServerDriver;
use App\Support\GameAccounts\ExternalGameAccountState;
use App\Support\GameAccounts\PreparedGameAccount;
use Illuminate\Database\Connection;

/** Adapter to the existing account reservation/recovery contract. */
final class UniversalLoginDriver implements LoginServerDriver
{
    /** @param array<string,mixed> $profile */
    public function __construct(
        private readonly array $profile,
        private readonly UniversalLoginAccountReader $reader,
        private readonly UniversalLoginAccountWriter $writer,
    ) {}

    public function supportsGameDriver(string $driver): bool
    {
        // The gateway independently verifies both profiles' account-ownership
        // contract. Never assume driver names have to match.
        return ($this->profile['config']['accounts']['ownership']['identity'] ?? null) === 'login';
    }

    public function encodePassword(string $password): string
    {
        return $this->writer->preparePassword($this->profile, $password);
    }

    public function acceptsCredential(string $credential): bool
    {
        return $this->writer->acceptsCredential($this->profile, $credential);
    }

    public function inspect(Connection $database, PreparedGameAccount $account): ExternalGameAccountState
    {
        return $this->reader->inspect($database, $this->profile, $account);
    }

    public function create(Connection $database, PreparedGameAccount $account): void
    {
        $this->writer->create($database, $this->profile, $account);
    }

    public function changePassword(Connection $database, string $login, string $password): bool
    {
        $fields = $this->profile['config']['accounts']['fields'];
        $table = $this->profile['config']['accounts']['table'];
        $stored = $database->table($table)->where($fields['login'], $login)->first([$fields['password']]);
        if ($stored === null) {
            return false;
        }
        // Never retry a lost-response bcrypt change with a freshly generated
        // credential; caller must have a stable operation key for such recovery.
        return $this->writer->changePreparedPassword($database, $this->profile, $login,
            (string) $stored->{$fields['password']}, $this->encodePassword($password));
    }

    public function summary(Connection $database, string $login): ?array
    {
        return $this->reader->summary($database, $this->profile, $login);
    }
}
