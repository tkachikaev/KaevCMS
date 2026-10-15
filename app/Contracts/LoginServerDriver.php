<?php

namespace App\Contracts;

use App\Support\GameAccounts\ExternalGameAccountState;
use App\Support\GameAccounts\PreparedGameAccount;
use Illuminate\Database\Connection;

interface LoginServerDriver
{
    public function supportsGameDriver(string $driver): bool;

    public function encodePassword(string $password): string;

    public function acceptsCredential(string $credential): bool;

    public function inspect(Connection $database, PreparedGameAccount $account): ExternalGameAccountState;

    public function create(Connection $database, PreparedGameAccount $account): void;

    public function changePassword(Connection $database, string $login, string $password): bool;

    /** @return array{login:string,created_at:string|null,last_active:int,status:string}|null */
    public function summary(Connection $database, string $login): ?array;
}
