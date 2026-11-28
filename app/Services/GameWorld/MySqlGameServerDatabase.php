<?php

namespace App\Services\GameWorld;

use App\Contracts\GameServerDatabaseGateway;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\Servers\MySqlExternalConnection;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use App\Support\UniversalDrivers\ProfileDatabaseEncoding;
use Illuminate\Database\Connection;
use RuntimeException;

final class MySqlGameServerDatabase implements GameServerDatabaseGateway
{
    public function __construct(private readonly MySqlExternalConnection $connection) {}

    /**
     * @template TResult
     *
     * @param  callable(Connection, array<string,mixed>|null=): TResult  $callback
     * @return TResult
     */
    public function run(GameServer $server, callable $callback): mixed
    {
        $server->loadMissing('loginServer');

        $snapshot = app(ConnectionProfileSnapshots::class)->get($server);

        return $this->connection->run('kaevcms_game_data_', ProfileDatabaseEncoding::apply(
            $this->connectionValues($server), $snapshot,
        ), fn (Connection $database) => $callback($database, $snapshot));
    }

    /** @return array{host:string,port:int,database:string,username:string,password:string,charset:string,collation?:string} */
    private function connectionValues(GameServer $server): array
    {
        $loginServer = $server->loginServer;
        if (! $loginServer instanceof LoginServer) {
            throw new RuntimeException('The selected GameServer has no LoginServer connection.');
        }

        if ($server->use_login_server_connection) {
            return [
                'host' => trim((string) $loginServer->database_host),
                'port' => (int) $loginServer->database_port,
                'database' => trim((string) $loginServer->database_name),
                'username' => trim((string) $loginServer->database_username),
                'password' => $loginServer->databasePassword() ?? '',
                'charset' => trim((string) $loginServer->database_charset),
            ];
        }

        return [
            'host' => trim((string) $server->database_host),
            'port' => (int) $server->database_port,
            'database' => trim((string) $server->database_name),
            'username' => trim((string) $server->database_username),
            'password' => $server->databasePassword() ?? '',
            'charset' => trim((string) $server->database_charset),
        ];
    }
}
