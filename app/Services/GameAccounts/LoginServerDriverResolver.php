<?php

namespace App\Services\GameAccounts;

use App\Contracts\LoginServerDriver;
use App\Models\LoginServer;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use App\Services\Servers\ServerDriverRegistry;
use RuntimeException;

final class LoginServerDriverResolver
{
    public function supports(string|LoginServer $driver): bool
    {
        if ($driver instanceof LoginServer) {
            try {
                $snapshot = app(ConnectionProfileSnapshots::class)->get($driver);
            } catch (RuntimeException) {
                return false;
            }
            if ($snapshot !== null) {
                return count(array_intersect(
                    ['account_lookup', 'account_creation', 'password_change'],
                    $snapshot['config']['capabilities'],
                )) === 3;
            }
            $driver = (string) $driver->driver;
        }
        return in_array($driver, [ServerDriverRegistry::MOBIUS_DRIVER, ServerDriverRegistry::MOBIUS_LEGACY_LOGIN_DRIVER, ServerDriverRegistry::RUSACIS_DRIVER], true);
    }

    public function resolve(string|LoginServer $driver): LoginServerDriver
    {
        if ($driver instanceof LoginServer) {
            $profile = app(ConnectionProfileSnapshots::class)->get($driver);
            if ($profile !== null) {
                return new UniversalLoginDriver($profile,
                    app(UniversalLoginAccountReader::class), app(UniversalLoginAccountWriter::class));
            }
            $driver = (string) $driver->driver;
        }
        return match ($driver) {
            ServerDriverRegistry::MOBIUS_DRIVER, ServerDriverRegistry::MOBIUS_LEGACY_LOGIN_DRIVER => app(MobiusLoginDriver::class),
            ServerDriverRegistry::RUSACIS_DRIVER => app(RusacisLoginDriver::class),
            default => throw new RuntimeException('The selected LoginServer driver does not support game accounts.'),
        };
    }
}
