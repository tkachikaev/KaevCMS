<?php

namespace App\Services\Servers;

use App\Contracts\GameServerDatabaseGateway;
use App\Contracts\GameServerOnlineCounter;
use App\Models\GameServer;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
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
        // An explicitly applied JSON snapshot owns the actual column mapping,
        // even when the server still carries a legacy driver identifier.
        $profile = app(ConnectionProfileSnapshots::class)->get($gameServer);
        if ($profile === null && ! in_array((string) $gameServer->driver, [ServerDriverRegistry::MOBIUS_DRIVER, ServerDriverRegistry::RUSACIS_DRIVER], true)) {
            throw new RuntimeException('Apply and verify the GameServer JSON profile before counting online players.');
        }
        $online = $profile['config']['characters']['fields']['online'] ?? null;
        $definition = $profile !== null
            ? (($profile['type'] ?? null) === 'game' && ($online['kind'] ?? null) === 'column'
                ? ['table' => $profile['config']['characters']['table'], 'column' => $online['column'], 'value' => 1]
                : null)
            : ($driver['online_count'] ?? null);
        $definition = $this->onlineCountDefinition($definition);
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
