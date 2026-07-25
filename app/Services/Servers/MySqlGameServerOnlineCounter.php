<?php

namespace App\Services\Servers;

use App\Contracts\GameServerDatabaseGateway;
use App\Contracts\GameServerOnlineCounter;
use App\Models\GameServer;
use Illuminate\Database\Connection;
use RuntimeException;

final class MySqlGameServerOnlineCounter implements GameServerOnlineCounter
{
    public function __construct(
        private readonly GameServerDatabaseGateway $database,
        private readonly ServerDriverRegistry $drivers,
    ) {}

    public function count(GameServer $gameServer): int
    {
        $driver = $this->drivers->gameDriver((string) $gameServer->driver);
        $definition = $this->onlineCountDefinition($driver['online_count'] ?? null);
        $table = $this->identifier($definition['table']);
        $column = $this->identifier($definition['column']);
        $onlineValue = $definition['value'];

        return $this->database->run(
            $gameServer,
            static fn (Connection $connection): int => (int) $connection
                ->table($table)
                ->where($column, $onlineValue)
                ->count(),
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
