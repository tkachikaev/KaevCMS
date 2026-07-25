<?php

namespace App\Services\Servers;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Models\GameServer;
use App\Models\LoginServer;
use InvalidArgumentException;

final class ServerConnectionTester
{
    public function __construct(
        private readonly ExternalDatabaseConnectionTester $tester,
        private readonly ServerDriverRegistry $drivers,
    ) {}

    /** @param array<string,mixed> $values */
    public function testLoginValues(array $values): array
    {
        $driver = $this->drivers->loginDriver((string) ($values['driver'] ?? ''));
        if ($driver === null) {
            throw new InvalidArgumentException('Unsupported LoginServer driver.');
        }

        return $this->withDriver(
            $this->tester->test($this->credentials($values), $driver['requirements'], $driver['ready']),
            (string) $values['driver'],
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

    /** @param array<string,mixed> $values */
    public function testGameValues(array $values, LoginServer $loginServer): array
    {
        $driver = $this->drivers->gameDriver((string) ($values['driver'] ?? ''));
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

        return $this->withDriver(
            $this->tester->test($this->credentials($connectionValues), $driver['requirements'], $driver['ready']),
            (string) $values['driver'],
            $driver,
        );
    }

    public function testGameServer(GameServer $server): array
    {
        $loginServer = $server->loginServer;
        if (! $loginServer instanceof LoginServer) {
            throw new InvalidArgumentException('GameServer has no LoginServer selected.');
        }

        return $this->testGameValues([
            'driver' => $server->driver,
            'use_login_server_connection' => $server->use_login_server_connection,
            'database_host' => $server->database_host,
            'database_port' => $server->database_port,
            'database_name' => $server->database_name,
            'database_username' => $server->database_username,
            'database_password' => $server->databasePassword() ?? '',
            'database_charset' => $server->database_charset,
        ], $loginServer);
    }

    /** @param array<string,mixed> $values @return array{host:string,port:int,database:string,username:string,password:string,charset:string} */
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

    /** @param array<string,mixed> $report @param array{label:string,description:string,ready:bool,requirements:list<array{table:string,columns:list<string>,any_columns?:list<string>,required:bool}>} $driver */
    private function withDriver(array $report, string $driverKey, array $driver): array
    {
        if (! $driver['ready']) {
            $report['compatible'] = null;
        }

        return $report + [
            'driver' => $driverKey,
            'driver_label' => $driver['label'],
            'driver_ready' => $driver['ready'],
        ];
    }
}
