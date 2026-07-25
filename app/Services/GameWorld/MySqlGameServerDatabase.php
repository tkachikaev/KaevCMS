<?php

namespace App\Services\GameWorld;

use App\Contracts\GameServerDatabaseGateway;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\Servers\MySqlSessionQueryTimeout;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

final class MySqlGameServerDatabase implements GameServerDatabaseGateway
{
    public function __construct(private readonly MySqlSessionQueryTimeout $queryTimeout) {}

    /**
     * @template TResult
     *
     * @param  callable(Connection): TResult  $callback
     * @return TResult
     */
    public function run(GameServer $server, callable $callback): mixed
    {
        $server->loadMissing('loginServer');
        $connectionName = 'kaevcms_game_data_'.Str::lower(Str::random(12));

        try {
            $connection = DB::connectUsing(
                $connectionName,
                $this->configuration($this->connectionValues($server)),
                true,
            );

            if (! $connection instanceof Connection) {
                throw new RuntimeException('Unsupported external game database connection type.');
            }

            $this->queryTimeout->apply($connection);

            return $callback($connection);
        } finally {
            try {
                DB::purge($connectionName);
            } catch (Throwable) {
                // Cleanup must not replace the query result.
            }
        }
    }

    /** @return array{host:string,port:int,database:string,username:string,password:string,charset:string} */
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

    /**
     * @param  array{host:string,port:int,database:string,username:string,password:string,charset:string}  $values
     * @return array<string,mixed>
     */
    private function configuration(array $values): array
    {
        return [
            'driver' => 'mysql',
            'host' => $values['host'],
            'port' => $values['port'],
            'database' => $values['database'],
            'username' => $values['username'],
            'password' => $values['password'],
            'charset' => $values['charset'] !== '' ? $values['charset'] : 'utf8mb4',
            'collation' => $this->collationFor($values['charset']),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [
                PDO::ATTR_TIMEOUT => max(1, min(30, (int) config('cms.external_database.connect_timeout_seconds', 3))),
            ],
        ];
    }

    private function collationFor(string $charset): string
    {
        return match ($charset) {
            'utf8' => 'utf8_unicode_ci',
            'latin1' => 'latin1_swedish_ci',
            'cp1251' => 'cp1251_general_ci',
            default => 'utf8mb4_unicode_ci',
        };
    }
}
