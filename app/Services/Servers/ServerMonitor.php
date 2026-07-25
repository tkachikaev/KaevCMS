<?php

namespace App\Services\Servers;

use App\Contracts\GameServerOnlineCounter;
use App\Contracts\ServicePortProbe;
use App\Models\GameServer;
use App\Models\LoginServer;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ServerMonitor
{
    public function __construct(
        private readonly ServicePortProbe $ports,
        private readonly GameServerOnlineCounter $onlineCounter,
        private readonly ServerDriverRegistry $drivers,
        private readonly ServerConnectionTester $connections,
        private readonly ServerDatabaseState $databaseState,
    ) {}

    /** @return array{login_servers:int,game_servers:int} */
    public function run(): array
    {
        $loginServers = LoginServer::query()->orderBy('id')->get();
        foreach ($loginServers as $loginServer) {
            $this->monitorLoginServer($loginServer);
        }

        $gameServers = GameServer::query()->with('loginServer')->orderBy('id')->get();
        foreach ($gameServers as $gameServer) {
            $this->monitorGameServer($gameServer);
        }

        return [
            'login_servers' => $loginServers->count(),
            'game_servers' => $gameServers->count(),
        ];
    }

    public function monitorLoginServer(LoginServer $loginServer): void
    {
        $this->checkLoginDatabase($loginServer);
        $driver = $this->drivers->loginDriver($loginServer->driver);
        $host = $this->serviceHost($loginServer->service_host, $loginServer->database_host);
        $port = $this->servicePort($loginServer->service_port, $driver['service_port'] ?? 2106);

        $this->updateServiceState(
            $loginServer,
            $host !== '' && $this->ports->isOpen($host, $port, $this->timeoutSeconds()),
        );
    }

    public function monitorGameServer(GameServer $gameServer): void
    {
        $databaseConfigured = $this->checkGameDatabase($gameServer);
        $driver = $this->drivers->gameDriver((string) $gameServer->driver);
        $fallbackHost = $gameServer->use_login_server_connection
            ? (string) $gameServer->loginServer?->database_host
            : (string) $gameServer->database_host;
        $host = $this->serviceHost($gameServer->service_host, $fallbackHost);
        $port = $this->servicePort($gameServer->service_port, $driver['service_port'] ?? 7777);
        $serviceOnline = $host !== ''
            && $this->ports->isOpen($host, $port, $this->timeoutSeconds());

        $this->updateServiceState($gameServer, $serviceOnline);

        if (! $databaseConfigured || ! $serviceOnline || ! is_array($driver['online_count'] ?? null)) {
            $gameServer->forceFill([
                'online_players' => null,
                'online_checked_at' => now(),
            ])->save();

            return;
        }

        try {
            $players = max(0, $this->onlineCounter->count($gameServer));
            $gameServer->forceFill([
                'online_players' => $players,
                'online_checked_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $gameServer->forceFill([
                'online_players' => null,
                'online_checked_at' => now(),
            ])->save();
            Log::warning('GameServer online count failed.', [
                'game_server_id' => $gameServer->id,
                'driver' => $gameServer->driver,
                'exception' => $exception::class,
                'code' => (string) $exception->getCode(),
            ]);
        }
    }

    private function checkLoginDatabase(LoginServer $server): bool
    {
        try {
            return $this->databaseState->apply($server, $this->connections->testLoginServer($server));
        } catch (Throwable $exception) {
            Log::warning('LoginServer database monitoring failed.', [
                'login_server_id' => $server->id,
                'exception' => $exception::class,
                'code' => (string) $exception->getCode(),
            ]);

            $this->databaseState->markUnknown($server, 'check_failed');

            return false;
        }
    }

    private function checkGameDatabase(GameServer $server): bool
    {
        try {
            return $this->databaseState->apply($server, $this->connections->testGameServer($server));
        } catch (Throwable $exception) {
            Log::warning('GameServer database monitoring failed.', [
                'game_server_id' => $server->id,
                'exception' => $exception::class,
                'code' => (string) $exception->getCode(),
            ]);

            $this->databaseState->markUnknown($server, 'check_failed');

            return false;
        }
    }

    private function updateServiceState(LoginServer|GameServer $server, bool $online): void
    {
        $failures = $online ? 0 : min(65535, ((int) $server->monitor_failures) + 1);
        $status = $online
            ? 'online'
            : ($failures >= $this->failureThreshold() ? 'offline' : 'unknown');
        $values = [
            'monitor_status' => $status,
            'monitor_failures' => $failures,
            'monitor_checked_at' => now(),
        ];

        if ($online) {
            $values['monitor_last_online_at'] = now();
        }

        $server->forceFill($values)->save();
    }

    private function serviceHost(?string $configured, ?string $fallback): string
    {
        $configured = trim((string) $configured);

        return $configured !== '' ? $configured : trim((string) $fallback);
    }

    private function servicePort(?int $configured, mixed $fallback): int
    {
        $port = $configured ?? (int) $fallback;

        return max(1, min(65535, $port));
    }

    private function timeoutSeconds(): float
    {
        return max(0.2, min(10.0, (float) config('cms.server_monitor.port_timeout_seconds', 1.0)));
    }

    private function failureThreshold(): int
    {
        return max(1, min(10, (int) config('cms.server_monitor.failure_threshold', 3)));
    }
}
