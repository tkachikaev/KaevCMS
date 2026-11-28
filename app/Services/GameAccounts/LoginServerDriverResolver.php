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
        return $this->makeDriver($this->profile($driver));
    }

    /** @return array{driver:LoginServerDriver,snapshot:array<string,mixed>|null} */
    public function forOperation(LoginServer $server, bool $lookupOnly = false): array
    {
        $snapshot = app(ConnectionProfileSnapshots::class)->get($server);
        $profile = $snapshot ?? $this->profile((string) $server->driver);
        $required = $lookupOnly ? ['account_lookup'] : ['account_lookup', 'account_creation', 'password_change'];
        if (array_diff($required, $profile['config']['capabilities']) !== []) {
            throw new RuntimeException('The selected LoginServer driver does not support game accounts.');
        }

        return ['driver' => $this->makeDriver($profile), 'snapshot' => $snapshot];
    }

    /** @param array<string,mixed> $profile */
    private function makeDriver(array $profile): LoginServerDriver
    {
        return new UniversalLoginDriver(
            $profile,
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
