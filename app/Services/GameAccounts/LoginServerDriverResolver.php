<?php

namespace App\Services\GameAccounts;

use App\Contracts\LoginServerDriver;
use App\Models\LoginServer;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use RuntimeException;

final class LoginServerDriverResolver
{
    public function supports(string|LoginServer $driver): bool
    {
        try {
            $profile = $this->profile($driver);
        } catch (RuntimeException) {
            return false;
        }

        return count(array_intersect(
            ['account_lookup', 'account_creation', 'password_change'],
            $profile['config']['capabilities'],
        )) === 3;
    }

    public function resolve(string|LoginServer $driver): LoginServerDriver
    {
        return new UniversalLoginDriver(
            $this->profile($driver),
            app(UniversalLoginAccountReader::class),
            app(UniversalLoginAccountWriter::class),
        );
    }

    /** @return array<string,mixed> */
    private function profile(string|LoginServer $driver): array
    {
        if ($driver instanceof LoginServer) {
            $snapshot = app(ConnectionProfileSnapshots::class)->get($driver);
            if ($snapshot !== null) {
                return $snapshot;
            }
            $driver = (string) $driver->driver;
        }

        return (new BundledProfileCatalog)->all()['login:'.$driver]
            ?? throw new RuntimeException('The selected LoginServer driver does not support game accounts.');
    }
}
