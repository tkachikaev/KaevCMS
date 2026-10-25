<?php

namespace App\Services\GameWorld;

use App\Contracts\GameServerDatabaseGateway;
use App\Contracts\GameWorldDriver;
use App\Models\GameServer;
use Illuminate\Database\Connection;

/** Executes only a previously verified connection-local JSON snapshot. */
final class UniversalGameWorldDriver implements GameWorldDriver
{
    /** @param array<string,mixed> $profile */
    public function __construct(
        private readonly array $profile,
        private readonly GameServerDatabaseGateway $database,
        private readonly UniversalGameWorldReader $world,
        private readonly UniversalGameCharacterReader $characters,
    ) {}

    public function capabilities(GameServer $server): array
    {
        return $this->database->run($server, fn (Connection $db): array => array_values(array_diff(
            $this->world->capabilities($db, $this->profile), ['character_rescue'],
        )));
    }

    public function ranking(GameServer $server, string $section, int $limit): array
    {
        return $this->database->run($server, fn (Connection $db): array => $this->world->ranking(
            $db, $this->profile, $section, $limit, $server->chronicle,
        ));
    }

    public function heroes(GameServer $server): array
    {
        return $this->database->run($server, fn (Connection $db): array => $this->world->heroes(
            $db, $this->profile, $server->chronicle,
        ));
    }

    public function castleOwners(GameServer $server): array
    {
        return $this->database->run($server, fn (Connection $db): array => $this->world->castleOwners($db, $this->profile));
    }

    public function charactersForAccount(GameServer $server, string $accountName, int $limit = 100): array
    {
        return $this->database->run($server, fn (Connection $db): array => $this->characters->charactersForAccount(
            $db, $this->profile, $accountName, $limit, $server->chronicle,
        ));
    }
}
