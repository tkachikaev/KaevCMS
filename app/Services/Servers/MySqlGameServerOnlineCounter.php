<?php

namespace App\Services\Servers;

use App\Contracts\GameServerDatabaseGateway;
use App\Contracts\GameServerOnlineCounter;
use App\Models\GameServer;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use Illuminate\Database\Connection;
use RuntimeException;

final class MySqlGameServerOnlineCounter implements GameServerOnlineCounter
{
    public function __construct(
        private readonly GameServerDatabaseGateway $database,
    ) {}

    public function count(GameServer $gameServer): int
    {
        $snapshot = app(ConnectionProfileSnapshots::class)->get($gameServer);
        $profile = $snapshot ?? (new BundledProfileCatalog)->all()['game:'.$gameServer->driver] ?? null;
        if ($profile === null) {
            throw new RuntimeException('Apply and verify the GameServer JSON profile before counting online players.');
        }
        $online = $profile['config']['characters']['fields']['online'] ?? null;
        if (($online['kind'] ?? null) !== 'column') {
            throw new RuntimeException('The selected GameServer driver does not provide online counting.');
        }
        $table = $profile['config']['characters']['table'];
        $column = $online['column'];

        return $this->database->run(
            $gameServer,
            static function (Connection $connection, ?array $active = null) use ($snapshot, $table, $column): int {
                ConnectionProfileSnapshots::assertUnchanged($snapshot, $active);

                return (int) $connection->table($table)->where($column, 1)->count();
            },
        );
    }
}
