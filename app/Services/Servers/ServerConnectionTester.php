<?php

namespace App\Services\Servers;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Exceptions\ExternalDatabaseDriverUnavailable;
use App\Exceptions\ExternalDatabaseSchemaMismatch;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\GameWorld\MobiusGameSchemaInspector;
use App\Services\GameWorld\MobiusGameSchemaProfile;
use App\Services\GameWorld\RusacisGameSchemaProfile;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use App\Support\UniversalDrivers\DriverProfileCatalog;
use App\Support\UniversalDrivers\ProfileDatabaseEncoding;
use InvalidArgumentException;

final class ServerConnectionTester
{
    private bool $probeCacheEnabled = false;

    /** @var array<string, array<string, mixed>> */
    private array $probeCache = [];

    public function __construct(
        private readonly ExternalDatabaseConnectionTester $tester,
        private readonly ServerDriverRegistry $drivers,
        private readonly MobiusGameSchemaInspector $mobiusSchemas,
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
    public function testLoginValues(array $values): array
    {
        $driverKey = (string) ($values['driver'] ?? '');
        $driver = $this->drivers->loginDriver($driverKey);
        if ($driver === null) {
            throw new InvalidArgumentException('Unsupported LoginServer driver.');
        }

        if (! in_array($driverKey, [ServerDriverRegistry::MOBIUS_DRIVER, ServerDriverRegistry::MOBIUS_LEGACY_LOGIN_DRIVER, ServerDriverRegistry::RUSACIS_DRIVER], true)) {
            $profile = app(DriverProfileCatalog::class)->find('login', $driverKey)['profile'] ?? null;
            if (! is_array($profile)) {
                throw new InvalidArgumentException('Unknown LoginServer JSON profile.');
            }

            return $this->testLoginProfileValues($values, $profile);
        }

        return $this->withLoginDriver(
            $this->testExternalDatabase(
                $this->credentials($values),
                $driver['requirements'],
                $driver['ready'],
            ),
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
        $report['capabilities'] = ($report['compatible'] ?? false) ? array_values(array_intersect(
            $profile['config']['capabilities'], ['characters', 'level', 'pvp', 'pk', 'play_time', 'heroes', 'castles'],
        )) : [];

        return $report;
    }

    /** @return array<string, mixed> */
    public function testLoginServer(LoginServer $server): array
    {
        $active = app(ConnectionProfileSnapshots::class)->get($server);
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

        return $this->testLoginValues([
            'driver' => $server->driver,
            'database_host' => $server->database_host,
            'database_port' => $server->database_port,
            'database_name' => $server->database_name,
            'database_username' => $server->database_username,
            'database_password' => $server->databasePassword() ?? '',
            'database_charset' => $server->database_charset,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function testGameValues(array $values, LoginServer $loginServer): array
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

        if (! in_array($driverKey, [ServerDriverRegistry::MOBIUS_DRIVER, ServerDriverRegistry::RUSACIS_DRIVER], true)) {
            $profile = app(DriverProfileCatalog::class)->find('game', $driverKey)['profile'] ?? null;
            if (! is_array($profile)) {
                throw new InvalidArgumentException('Unknown GameServer JSON profile.');
            }

            return $this->testGameProfileValues($values, $loginServer, $profile);
        }

        return $this->withGameDriver(
            $this->testExternalDatabase(
                $this->credentials($connectionValues),
                $driver['requirements'],
                $driver['ready'],
            ),
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

        $active = app(ConnectionProfileSnapshots::class)->get($server);
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
        ], $loginServer);
    }

    /** @param array<string,mixed> $connection
     * @param  array<string,mixed>  $profile
     * @return array<string,mixed>
     */
    private function testUniversalProfile(array $connection, array $profile): array
    {
        // A test double may only implement the legacy interface; real MySQL checks
        // use the expanded read-only probe on the same physical DB connection.
        if ($this->tester instanceof MySqlExternalDatabaseConnectionTester) {
            return $this->tester->testProfile($connection, $profile);
        }

        return $this->testExternalDatabase($connection, $profile['config']['requirements'], true);
    }

    /**
     * @param  array{host: string, port: int, database: string, username: string, password: string, charset: string, collation?: string}  $connection
     * @param  list<array{table: string, columns: list<string>, any_columns?: list<string>, optional_columns?: list<string>, required: bool}>  $requirements
     * @return array<string, mixed>
     */
    private function testExternalDatabase(array $connection, array $requirements, bool $driverReady): array
    {
        if (! $this->probeCacheEnabled) {
            return $this->tester->test($connection, $requirements, $driverReady);
        }

        $payload = json_encode(
            [$connection, $requirements, $driverReady],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $fingerprint = hash('sha256', $payload);

        if (! array_key_exists($fingerprint, $this->probeCache)) {
            $this->probeCache[$fingerprint] = $this->tester->test($connection, $requirements, $driverReady);
        }

        return $this->probeCache[$fingerprint];
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
     * @param  array{label: string, description: string, ready: bool, service_port: int, character_created_at_column?: string|null, online_count?: array{table: string, column: string, value: int|string}, statistics?: list<string>, capabilities: list<string>, optional_capabilities: array<string, string>, requirements: list<array{table: string, columns: list<string>, any_columns?: list<string>, optional_columns?: list<string>, required: bool}>}  $driver
     * @return array<string, mixed>
     */
    private function withGameDriver(array $report, string $driverKey, array $driver, string $chronicle): array
    {
        $report = $this->withDriver($report, $driverKey, $driver['label'], $driver['ready']);
        $profile = $driverKey === ServerDriverRegistry::MOBIUS_DRIVER
            ? $this->mobiusProfile($report, $chronicle)
            : null;
        $report['schema_profile'] = $profile?->name;
        if ($driverKey === ServerDriverRegistry::RUSACIS_DRIVER && ($report['compatible'] ?? false) === true) {
            $report['schema_profile'] = RusacisGameSchemaProfile::NAME;
        }
        $report['capabilities'] = ($report['compatible'] ?? false) === true
            ? ($profile?->capabilities() ?? $this->availableCapabilities(
                $report,
                $driver['capabilities'],
                $driver['optional_capabilities'],
            ))
            : [];

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
    private function mobiusProfile(array $report, string $chronicle): ?MobiusGameSchemaProfile
    {
        if (($report['connected'] ?? false) !== true) {
            return null;
        }

        $characters = $this->tableCheck($report, 'characters');
        $matched = is_array($characters['matched_any_columns'] ?? null)
            ? $characters['matched_any_columns']
            : [];
        $matchedOptional = is_array($characters['matched_optional_columns'] ?? null)
            ? $characters['matched_optional_columns']
            : [];

        return $this->mobiusSchemas->profileForColumns(
            hasKarma: in_array('karma', $matched, true),
            hasReputation: in_array('reputation', $matched, true),
            heroesAvailable: $this->tableIsAvailable($report, 'heroes'),
            castlesAvailable: $this->tableIsAvailable($report, 'castle'),
            characterRescueAvailable: count(array_intersect(['x', 'y', 'z'], $matchedOptional)) === 3,
            chronicle: $chronicle,
        );
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
