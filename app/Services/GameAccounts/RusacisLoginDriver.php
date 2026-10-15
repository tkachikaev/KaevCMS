<?php

namespace App\Services\GameAccounts;

use App\Contracts\LoginServerDriver;
use App\Services\Servers\ServerDriverRegistry;
use App\Support\GameAccounts\ExternalGameAccountState;
use App\Support\GameAccounts\PreparedGameAccount;
use Illuminate\Database\Connection;

final class RusacisLoginDriver implements LoginServerDriver
{
    public function __construct(private readonly RusacisPasswordEncoder $passwords) {}

    public function supportsGameDriver(string $driver): bool
    {
        return $driver === ServerDriverRegistry::RUSACIS_DRIVER;
    }

    public function encodePassword(string $password): string
    {
        return $this->passwords->encode($password);
    }

    public function acceptsCredential(string $credential): bool
    {
        return preg_match('/\A\$2a\$(?:0[4-9]|[12][0-9]|3[01])\$[.\/A-Za-z0-9]{53}\z/', $credential) === 1;
    }

    public function inspect(Connection $database, PreparedGameAccount $account): ExternalGameAccountState
    {
        $stored = $database->table('accounts')->where('login', $account->login)->first(['password']);
        if ($stored === null) {
            return ExternalGameAccountState::Missing;
        }

        // Compare the persisted proof, never a newly salted BCrypt hash.
        return hash_equals($account->credential, (string) $stored->password)
            ? ExternalGameAccountState::Matching : ExternalGameAccountState::Conflict;
    }

    public function create(Connection $database, PreparedGameAccount $account): void
    {
        // last_active, access_level and last_server have server-owned defaults.
        $database->table('accounts')->insert(['login' => $account->login, 'password' => $account->credential]);
    }

    public function changePassword(Connection $database, string $login, string $password): bool
    {
        return $database->table('accounts')->where('login', $login)->update(['password' => $this->encodePassword($password)]) === 1;
    }

    public function summary(Connection $database, string $login): ?array
    {
        $account = $database->table('accounts')->where('login', $login)->first(['login', 'last_active', 'access_level']);
        if ($account === null) {
            return null;
        }

        return [
            'login' => (string) $account->login,
            'created_at' => null,
            'last_active' => is_numeric($account->last_active) ? (int) $account->last_active : 0,
            'status' => is_numeric($account->access_level) && (int) $account->access_level < 0 ? 'blocked' : 'active',
        ];
    }
}
