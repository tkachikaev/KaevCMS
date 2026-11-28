<?php

namespace App\Services\Servers;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Exceptions\ExternalDatabaseDriverUnavailable;
use App\Exceptions\ExternalDatabaseSchemaMismatch;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Support\Database\TableExistence;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use App\Support\UniversalDrivers\DriverProfileCatalog;
use App\Support\UniversalDrivers\ProfileDatabaseEncoding;
use App\Support\UniversalDrivers\ProfileSchema;
use InvalidArgumentException;

final class ServerConnectionTester
{
    private bool $probeCacheEnabled = false;

    /** @var array<string, array<string, mixed>> */
    private array $probeCache = [];

    public function __construct(
        private readonly ExternalDatabaseConnectionTester $tester,
        private readonly ServerDriverRegistry $drivers,
    ) {}

    /**
     * Cache identical physical probes only for the duration of one diagnostics refresh.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function withProbeCache(callable $callback): mixed
    {
        $previousEnabled = $this->probeCacheEnabled;
        $previousCache = $this->probeCache;
        $this->probeCacheEnabled = true;
        $this->probeCache = [];

        try {
            return $callback();
        } finally {
            $this->probeCacheEnabled = $previousEnabled;
            $this->probeCache = $previousCache;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function testLoginValues(array $values, bool $useCatalogOverride = true): array
    {
        $driverKey = (string) ($values['driver'] ?? '');
        $driver = $this->drivers->loginDriver($driverKey);
        if ($driver === null) {
            throw new InvalidArgumentException('Unsupported LoginServer driver.');
        }

        $bundled = (new BundledProfileCatalog)->all()['login:'.$driverKey] ?? null;
        $entry = ($useCatalogOverride || $bundled === null) && app(TableExistence::class)->has('universal_driver_profiles')
            ? app(DriverProfileCatalog::class)->find('login', $driverKey)
            : null;
        if ($bundled === null || ($entry['modified'] ?? false)) {
            $profile = $entry['profile'] ?? null;
            if (! is_array($profile)) {
                throw new InvalidArgumentException('Unknown LoginServer JSON profile.');
            }

            return $this->testLoginProfileValues($values, $profile);
        }

        return $this->withLoginDriver(
            $this->testUniversalProfile($this->credentials($values), $bundled),
            $driverKey,
            $driver,
        );
    }

    /**
     * Verify the exact JSON candidate, not whatever is currently in the catalog.
     * The result does not grant permission to apply or execute a driver.
     *
     * @param  array<string,mixed>  $values
     * @param  array<string,mixed>  $profile
     * @return array<string,mixed>
     */
    public function testLoginProfileValues(array $values, array $profile): array
    {
        if ($profile['type'] !== 'login' || $profile['config']['database']['dialect'] !== 'mysql') {
            throw new InvalidArgumentException('The selected LoginServer profile is not supported.');
        }
        $report = $this->testUniversalProfile(ProfileDatabaseEncoding::apply($this->credentials($values), $profile), $profile);
        $report = $this->withDriver($report, $profile['id'], $profile['name'], true);
        $report['schema_profile'] = ($report['compatible'] ?? false) ? 'universal:'.$profile['id'] : null;
        $report['capabilities'] = ($report['compatible'] ?? false) ? $profile['config']['capabilities'] : [];

        return $report;
    }

    /**
     * @param  array<string,mixed>  $values
     * @param  array<string,mixed>  $profile
     * @return array<string,mixed>
     */
    public function testGameProfileValues(array $values, LoginServer $loginServer, array $profile): array
    {
        if ($profile['type'] !== 'game' || $profile['config']['database']['dialect'] !== 'mysql') {
            throw new InvalidArgumentException('The selected GameServer profile is not supported.');
        }
        $connectionValues = (bool) ($values['use_login_server_connection'] ?? false)
            ? [
                'database_host' => $loginServer->database_host,
                'database_port' => $loginServer->database_port,
                'database_name' => $loginServer->database_name,
                'database_username' => $loginServer->database_username,
                'database_password' => $loginServer->databasePassword() ?? '',
                'database_charset' => $loginServer->database_charset,
            ] : $values;
        $report = $this->testUniversalProfile(ProfileDatabaseEncoding::apply($this->credentials($connectionValues), $profile), $profile);
        $report = $this->withDriver($report, $profile['id'], $profile['name'], true);
        $report['schema_profile'] = ($report['compatible'] ?? false) ? 'universal:'.$profile['id'] : null;
        $report['capabilities'] = ($report['compatible'] ?? false)
            ? ($report['game_capabilities'] ?? [])
            : [];
        $report['unavailable_capabilities'] = ($report['compatible'] ?? false)
            ? array_values(array_diff($profile['config']['capabilities'], $report['capabilities']))
            : [];

        return $report;
    }

    /** @return array<string, mixed> */
    public function testLoginServer(LoginServer $server): array
    {
        try {
            $active = app(ConnectionProfileSnapshots::class)->get($server);
        } catch (ExternalDatabaseDriverUnavailable) {
            return $this->verificationRequired();
        }
        if ($active !== null) {
            return $this->testLoginProfileValues([
                'database_host' => $server->database_host,
                'database_port' => $server->database_port,
                'database_name' => $server->database_name,
                'database_username' => $server->database_username,
                'database_password' => $server->databasePassword() ?? '',
                'database_charset' => $server->database_charset,
            ], $active);
        }

        if (! isset((new BundledProfileCatalog)->all()['login:'.$server->driver])
            && app(DriverProfileCatalog::class)->find('login', (string) $server->driver) !== null) {
            return $this->verificationRequired();
        }

        return $this->testLoginValues([
            'driver' => $server->driver,
            'database_host' => $server->database_host,
            'database_port' => $server->database_port,
            'database_name' => $server->database_name,
            'database_username' => $server->database_username,
            'database_password' => $server->databasePassword() ?? '',
            'database_charset' => $server->database_charset,
        ], useCatalogOverride: false);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function testGameValues(array $values, LoginServer $loginServer, bool $useCatalogOverride = true): array
    {
        $driverKey = (string) ($values['driver'] ?? '');
        $driver = $this->drivers->gameDriver($driverKey);
        if ($driver === null) {
            throw new InvalidArgumentException('Unsupported GameServer driver.');
        }

        $connectionValues = (bool) ($values['use_login_server_connection'] ?? false)
            ? [
                'database_host' => $loginServer->database_host,
                'database_port' => $loginServer->database_port,
                'database_name' => $loginServer->database_name,
                'database_username' => $loginServer->database_username,
                'database_password' => $loginServer->databasePassword() ?? '',
                'database_charset' => $loginServer->database_charset,
            ]
            : $values;

        $bundled = (new BundledProfileCatalog)->all()['game:'.$driverKey] ?? null;
        $entry = ($useCatalogOverride || $bundled === null) && app(TableExistence::class)->has('universal_driver_profiles')
            ? app(DriverProfileCatalog::class)->find('game', $driverKey)
            : null;
        if ($bundled === null || ($entry['modified'] ?? false)) {
            $profile = $entry['profile'] ?? null;
            if (! is_array($profile)) {
                throw new InvalidArgumentException('Unknown GameServer JSON profile.');
            }

            return $this->testGameProfileValues($values, $loginServer, $profile);
        }

        return $this->withGameDriver(
            $this->testUniversalProfile($this->credentials($connectionValues), $bundled),
            $driverKey,
            $driver,
            (string) ($values['chronicle'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function testGameServer(GameServer $server): array
    {
        $loginServer = $server->loginServer;
        if (! ($loginServer instanceof LoginServer)) {
            throw new InvalidArgumentException('GameServer has no LoginServer selected.');
        }

        try {
            $active = app(ConnectionProfileSnapshots::class)->get($server);
        } catch (ExternalDatabaseDriverUnavailable) {
            return $this->verificationRequired();
        }
        if ($active !== null) {
            return $this->testGameProfileValues([
                'use_login_server_connection' => $server->use_login_server_connection,
                'database_host' => $server->database_host,
                'database_port' => $server->database_port,
                'database_name' => $server->database_name,
                'database_username' => $server->database_username,
                'database_password' => $server->databasePassword() ?? '',
                'database_charset' => $server->database_charset,
            ], $loginServer, $active);
        }

        if (! isset((new BundledProfileCatalog)->all()['game:'.$server->driver])
            && app(DriverProfileCatalog::class)->find('game', (string) $server->driver) !== null) {
            return $this->verificationRequired();
        }

        return $this->testGameValues([
            'driver' => $server->driver,
            'chronicle' => $server->chronicle,
            'use_login_server_connection' => $server->use_login_server_connection,
            'database_host' => $server->database_host,
            'database_port' => $server->database_port,
            'database_name' => $server->database_name,
            'database_username' => $server->database_username,
            'database_password' => $server->databasePassword() ?? '',
            'database_charset' => $server->database_charset,
        ], $loginServer, useCatalogOverride: false);
    }

    /** @return array<string,mixed> */
    private function verificationRequired(): array
    {
        return [
            'connected' => false,
            'compatible' => false,
            'driver_ready' => false,
            'error' => 'driver_verification_required',
            'checks' => [],
        ];
    }

    /**
     * @param  array{host:string,port:int,database:string,username:string,password:string,charset:string,collation?:string}  $connection
     * @param  array<string,mixed>  $profile
     * @return array<string,mixed>
     */
    private function testUniversalProfile(array $connection, array $profile): array
    {
        $run = fn (): array => $this->tester instanceof MySqlExternalDatabaseConnectionTester
            ? $this->tester->testProfile($connection, $profile)
            : $this->tester->test($connection, $profile['config']['requirements'], true);

        if (! $this->probeCacheEnabled) {
            return $run();
        }

        $fingerprint = hash('sha256', json_encode([$connection, $profile], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $this->probeCache[$fingerprint] ??= $run();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{host: string, port: int, database: string, username: string, password: string, charset: string, collation?: string}
     */
    private function credentials(array $values): array
    {
        return [
            'host' => (string) ($values['database_host'] ?? ''),
            'port' => (int) ($values['database_port'] ?? 3306),
            'database' => (string) ($values['database_name'] ?? ''),
            'username' => (string) ($values['database_username'] ?? ''),
            'password' => (string) ($values['database_password'] ?? ''),
            'charset' => (string) ($values['database_charset'] ?? 'utf8mb4'),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array{label: string, description: string, ready: bool, service_port: int, schema_profile: string, capabilities: list<string>, optional_capabilities: array<string, string>, requirements: list<array{table: string, columns: list<string>, any_columns?: list<string>, optional_columns?: list<string>, required: bool}>}  $driver
     * @return array<string, mixed>
     */
    private function withLoginDriver(array $report, string $driverKey, array $driver): array
    {
        $report = $this->withDriver($report, $driverKey, $driver['label'], $driver['ready']);
        $report['schema_profile'] = ($report['connected'] ?? false) === true && $driver['ready']
            ? $driver['schema_profile']
            : null;
        $report['capabilities'] = ($report['compatible'] ?? false) === true
            ? $this->availableCapabilities($report, $driver['capabilities'], $driver['optional_capabilities'])
            : [];

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array{label: string, description: string, ready: bool, service_port: int, schema_profile: string, character_created_at_column?: string|null, online_count?: array{table: string, column: string, value: int|string}, statistics?: list<string>, capabilities: list<string>, optional_capabilities: array<string, string>, requirements: list<array{table: string, columns: list<string>, any_columns?: list<string>, optional_columns?: list<string>, required: bool}>}  $driver
     * @return array<string, mixed>
     */
    private function withGameDriver(array $report, string $driverKey, array $driver, string $chronicle): array
    {
        $report = $this->withDriver($report, $driverKey, $driver['label'], $driver['ready']);
        $characters = $this->tableCheck($report, 'characters');
        if ($driverKey === ServerDriverRegistry::MOBIUS_DRIVER && ($report['connected'] ?? false) === true) {
            $field = (new BundledProfileCatalog)->all()['game:l2j_mobius']['config']['characters']['fields']['reputation'];
            $matched = is_array($characters['matched_any_columns'] ?? null) ? $characters['matched_any_columns'] : [];
            $column = ProfileSchema::chosenColumn($field, $matched, $chronicle);
            $report['schema_profile'] = match ($column) {
                'karma' => 'mobius_legacy',
                'reputation' => 'mobius_modern',
                default => null,
            };
        } elseif (($report['compatible'] ?? false) === true) {
            $report['schema_profile'] = $driver['schema_profile'];
        }

        if (($report['compatible'] ?? false) === true) {
            $report['capabilities'] = array_key_exists('game_capabilities', $report)
                ? array_values(array_diff($report['game_capabilities'], ['characters']))
                : $this->availableCapabilities($report, $driver['capabilities'], $driver['optional_capabilities']);
            // Older connection testers only supply column checks.
            $optional = is_array($characters['matched_optional_columns'] ?? null) ? $characters['matched_optional_columns'] : [];
            if (! array_key_exists('game_capabilities', $report)
                && count(array_intersect(['x', 'y', 'z'], $optional)) === 3) {
                $report['capabilities'][] = 'character_rescue';
            }
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function withDriver(array $report, string $driverKey, string $driverLabel, bool $driverReady): array
    {
        if (! $driverReady && ($report['error'] ?? null) === null) {
            $report['compatible'] = null;
            if (($report['connected'] ?? false) === true) {
                $report['error_class'] = ExternalDatabaseDriverUnavailable::class;
            }
        } elseif (($report['connected'] ?? false) === true && ($report['compatible'] ?? false) !== true && ($report['error'] ?? null) === null) {
            $report['error_class'] = ExternalDatabaseSchemaMismatch::class;
        }

        $report['driver'] = $driverKey;
        $report['driver_label'] = $driverLabel;
        $report['driver_ready'] = $driverReady;
        $report['schema_profile'] = null;
        $report['capabilities'] = [];

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<string>  $base
     * @param  array<string, string>  $optional
     * @return list<string>
     */
    private function availableCapabilities(array $report, array $base, array $optional): array
    {
        $capabilities = $base;

        foreach ($optional as $table => $capability) {
            if ($this->tableIsAvailable($report, $table)) {
                $capabilities[] = $capability;
            }
        }

        return array_values(array_unique($capabilities));
    }

    /** @param array<string, mixed> $report */
    private function tableIsAvailable(array $report, string $table): bool
    {
        $check = $this->tableCheck($report, $table);

        return ($check['table_exists'] ?? false) === true
            && ($check['missing_columns'] ?? []) === [];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function tableCheck(array $report, string $table): array
    {
        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];

        foreach ($checks as $check) {
            if (is_array($check) && ($check['table'] ?? null) === $table) {
                return $check;
            }
        }

        return [];
    }
}
