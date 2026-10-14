<?php

namespace App\Services\GameAccounts;

use App\Contracts\LoginServerDriver;
use App\Services\Servers\ServerDriverRegistry;
use RuntimeException;

final class LoginServerDriverResolver
{
    public function supports(string $driver): bool
    {
        return in_array($driver, [ServerDriverRegistry::MOBIUS_DRIVER, ServerDriverRegistry::MOBIUS_LEGACY_LOGIN_DRIVER, ServerDriverRegistry::RUSACIS_DRIVER], true);
    }

    public function resolve(string $driver): LoginServerDriver
    {
        return match ($driver) {
            ServerDriverRegistry::MOBIUS_DRIVER, ServerDriverRegistry::MOBIUS_LEGACY_LOGIN_DRIVER => app(MobiusLoginDriver::class),
            ServerDriverRegistry::RUSACIS_DRIVER => app(RusacisLoginDriver::class),
            default => throw new RuntimeException('The selected LoginServer driver does not support game accounts.'),
        };
    }
}
