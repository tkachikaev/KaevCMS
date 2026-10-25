<?php

namespace App\Services\Servers;

use App\Models\GameServer;
use App\Models\LoginServer;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use App\Support\UniversalDrivers\ProfileValidationException;
use Illuminate\Support\Facades\DB;

/**
 * Applies the exact catalog candidate only when the target real DB passes its
 * schema probe. Never writes to a game DB; legacy behavior remains available
 * until this explicit activation succeeds.
 */
final class UniversalDriverActivation
{
    public function __construct(
        private readonly ConnectionProfileSnapshots $snapshots,
        private readonly ServerConnectionTester $tester,
        private readonly ServerDatabaseState $state,
    ) {}

    /** @return array<string,mixed> */
    public function apply(LoginServer|GameServer $server): array
    {
        $report = [];
        $this->snapshots->verifyAndRecord($server, (string) $server->driver, function ($locked, array $profile) use (&$report): bool {
            if ($locked instanceof LoginServer) {
                $report = $this->tester->testLoginProfileValues([
                    'database_host' => $locked->database_host,
                    'database_port' => $locked->database_port,
                    'database_name' => $locked->database_name,
                    'database_username' => $locked->database_username,
                    'database_password' => $locked->databasePassword() ?? '',
                    'database_charset' => $locked->database_charset,
                ], $profile);
            } else {
                $login = LoginServer::query()->find($locked->login_server_id);
                if (! $login instanceof LoginServer) {
                    throw new ProfileValidationException('No LoginServer selected.');
                }
                $report = $this->tester->testGameProfileValues([
                    'use_login_server_connection' => $locked->use_login_server_connection,
                    'database_host' => $locked->database_host,
                    'database_port' => $locked->database_port,
                    'database_name' => $locked->database_name,
                    'database_username' => $locked->database_username,
                    'database_password' => $locked->databasePassword() ?? '',
                    'database_charset' => $locked->database_charset,
                ], $login, $profile);
            }

            return ($report['connected'] ?? false) === true && ($report['compatible'] ?? false) === true
                && ($report['driver_ready'] ?? false) === true;
        });
        $this->state->apply($server, $report);

        return $report;
    }

    /** Invalidate stale verification after credential/target DB changes. */
    public function invalidate(LoginServer|GameServer $server): void
    {
        if (! $server->exists) {
            return;
        }
        DB::table('universal_driver_connection_snapshots')->where('connection_type', $server instanceof LoginServer ? 'login' : 'game')
            ->where('connection_id', $server->getKey())->delete();
        if ($server instanceof LoginServer) {
            // A GameServer may inherit its LoginServer DB connection.
            $ids = GameServer::query()->where('login_server_id', $server->id)
                ->where('use_login_server_connection', true)->pluck('id');
            if ($ids->isNotEmpty()) {
                DB::table('universal_driver_connection_snapshots')->where('connection_type', 'game')
                    ->whereIn('connection_id', $ids)->delete();
            }
        }
    }
}
