<?php

namespace App\Services\Servers;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Exceptions\ExternalDatabaseDriverUnavailable;
use App\Exceptions\ExternalDatabaseSchemaMismatch;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\GameWorld\MobiusGameSchemaInspector;
use App\Services\GameWorld\MobiusGameSchemaProfile;
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

    /** @param array<string, mixed> $values */
    public function testLoginValues(array $values): array
    {
        $driverKey = (string) ($values['driver'] ?? '');
        $driver = $this->drivers->loginDriver($driverKey);
        if ($driver === null) {
            throw new InvalidArgumentException('Unsupported LoginServer driver.');
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

    public function testLoginServer(LoginServer $server): array
    {
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

    /** @param array<string, mixed> $values */
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

    public function testGameServer(GameServer $server): array
    {
        $loginServer = $server->loginServer;
        if (! ($loginServer instanceof LoginServer)) {
            throw new InvalidArgumentException('GameServer has no LoginServer selected.');
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

    /**
     * @param  array{host: string, port: int, database: string, username: string, password: string, charset: string}  $connection
     * @param  list<array{table: string, columns: list<string>, any_columns?: list<string>, required: bool}>  $requirements
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
     * @return array{host: string, port: int, database: string, username: string, password: string, charset: string}
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
     * @param  array{label: string, description: string, ready: bool, service_port: int, schema_profile: string, capabilities: list<string>, optional_capabilities: array<string, string>, requirements: list<array{table: string, columns: list<string>, any_columns?: list<string>, required: bool}>}  $driver
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
     * @param  array{label: string, description: string, ready: bool, service_port: int, character_created_at_column?: string|null, online_count?: array{table: string, column: string, value: int|string}, statistics?: list<string>, capabilities: list<string>, optional_capabilities: array<string, string>, requirements: list<array{table: string, columns: list<string>, any_columns?: list<string>, required: bool}>}  $driver
     * @return array<string, mixed>
     */
    private function withGameDriver(array $report, string $driverKey, array $driver, string $chronicle): array
    {
        $report = $this->withDriver($report, $driverKey, $driver['label'], $driver['ready']);
        $profile = $driverKey === ServerDriverRegistry::MOBIUS_DRIVER
            ? $this->mobiusProfile($report, $chronicle)
            : null;
        $report['schema_profile'] = $profile?->name;
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
        if (! $driverReady) {
            $report['compatible'] = null;
            if (($report['connected'] ?? false) === true) {
                $report['error_class'] = ExternalDatabaseDriverUnavailable::class;
            }
        } elseif (($report['connected'] ?? false) === true && ($report['compatible'] ?? false) !== true) {
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

        return $this->mobiusSchemas->profileForColumns(
            hasKarma: in_array('karma', $matched, true),
            hasReputation: in_array('reputation', $matched, true),
            heroesAvailable: $this->tableIsAvailable($report, 'heroes'),
            castlesAvailable: $this->tableIsAvailable($report, 'castle'),
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
