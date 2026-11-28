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
        $definition = ($online['kind'] ?? null) === 'column'
            ? ['table' => $profile['config']['characters']['table'], 'column' => $online['column'], 'value' => 1]
            : null;
        $definition = $this->onlineCountDefinition($definition);
        $table = $this->identifier($definition['table']);
        $column = $this->identifier($definition['column']);
        $onlineValue = $definition['value'];

        return $this->database->run(
            $gameServer,
            static function (Connection $connection, ?array $active = null) use ($snapshot, $table, $column, $onlineValue): int {
                ConnectionProfileSnapshots::assertUnchanged($snapshot, $active);

                return (int) $connection->table($table)->where($column, $onlineValue)->count();
            },
        );
    }

    /** @return array{table:string,column:string,value:int|string} */
    private function onlineCountDefinition(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('The selected GameServer driver does not provide online counting.');
        }

        $table = $value['table'] ?? null;
        $column = $value['column'] ?? null;
        $onlineValue = $value['value'] ?? null;

        if (! is_string($table)
            || ! is_string($column)
            || (! is_int($onlineValue) && ! is_string($onlineValue))) {
            throw new RuntimeException('The online counter contains an invalid definition.');
        }

        return [
            'table' => $table,
            'column' => $column,
            'value' => $onlineValue,
        ];
    }

    private function identifier(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $value) !== 1) {
            throw new RuntimeException('The online counter contains an unsafe database identifier.');
        }

        return $value;
    }
}
