<?php

namespace App\Services\GameAccounts;

use App\Contracts\LoginServerDriver;
use App\Services\Servers\ServerDriverRegistry;
use App\Support\GameAccounts\ExternalGameAccountState;
use App\Support\GameAccounts\PreparedGameAccount;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

final class MobiusLoginDriver implements LoginServerDriver
{
    public function __construct(private readonly MobiusPasswordEncoder $passwords) {}

    public function supportsGameDriver(string $driver): bool
    {
        return $driver === ServerDriverRegistry::MOBIUS_DRIVER;
    }

    public function encodePassword(string $password): string
    {
        return $this->passwords->encode($password);
    }

    public function acceptsCredential(string $credential): bool
    {
        $decoded = base64_decode($credential, true);

        return is_string($decoded) && strlen($decoded) === 20 && base64_encode($decoded) === $credential;
    }

    public function inspect(Connection $database, PreparedGameAccount $account): ExternalGameAccountState
    {
        $stored = $database->table('accounts')->where('login', $account->login)->first(['password', 'email']);
        if ($stored === null) {
            return ExternalGameAccountState::Missing;
        }

        return hash_equals($account->credential, (string) $stored->password)
            && hash_equals($account->email, Str::lower(trim((string) $stored->email)))
                ? ExternalGameAccountState::Matching : ExternalGameAccountState::Conflict;
    }

    public function create(Connection $database, PreparedGameAccount $account): void
    {
        $database->table('accounts')->insert(['login' => $account->login, 'password' => $account->credential, 'email' => $account->email]);
    }

    public function changePassword(Connection $database, string $login, string $password): bool
    {
        return $database->table('accounts')->where('login', $login)->update(['password' => $this->encodePassword($password)]) === 1;
    }

    public function summary(Connection $database, string $login): ?array
    {
        $account = $database->table('accounts')->where('login', $login)->first(['login', 'created_time', 'lastactive', 'accessLevel']);
        if ($account === null) {
            return null;
        }

        return [
            'login' => (string) $account->login,
            'created_at' => is_scalar($account->created_time) ? (string) $account->created_time : null,
            'last_active' => is_numeric($account->lastactive) ? (int) $account->lastactive : 0,
            'status' => is_numeric($account->accessLevel) && (int) $account->accessLevel < 0 ? 'blocked' : 'active',
        ];
    }
}
