<?php

namespace App\Services\Servers;

use App\Models\GameServer;
use App\Models\LoginServer;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExternalDatabaseDiagnostics
{
    public function __construct(
        private readonly ServerConnectionTester $connections,
        private readonly ServerDatabaseState $databaseState,
    ) {}

    /** @return array{login_servers:int,game_servers:int,successful:int,failed:int} */
    public function refresh(): array
    {
        $successful = 0;
        $failed = 0;
        $loginServers = LoginServer::query()->orderBy('id')->get();

        foreach ($loginServers as $server) {
            try {
                if ($this->databaseState->apply($server, $this->connections->testLoginServer($server))) {
                    $successful++;
                } else {
                    $failed++;
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->databaseState->markUnknown($server, 'check_failed', $exception::class);
                Log::warning('LoginServer database diagnostics failed.', [
                    'login_server_id' => $server->id,
                    'exception' => $exception::class,
                    'code' => (string) $exception->getCode(),
                ]);
            }
        }

        $gameServers = GameServer::query()->with('loginServer')->orderBy('id')->get();
        foreach ($gameServers as $server) {
            try {
                if ($this->databaseState->apply($server, $this->connections->testGameServer($server))) {
                    $successful++;
                } else {
                    $failed++;
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->databaseState->markUnknown($server, 'check_failed', $exception::class);
                Log::warning('GameServer database diagnostics failed.', [
                    'game_server_id' => $server->id,
                    'exception' => $exception::class,
                    'code' => (string) $exception->getCode(),
                ]);
            }
        }

        return [
            'login_servers' => $loginServers->count(),
            'game_servers' => $gameServers->count(),
            'successful' => $successful,
            'failed' => $failed,
        ];
    }
}
