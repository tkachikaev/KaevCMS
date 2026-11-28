<?php

namespace App\Services\GameWorld;

use App\Contracts\GameServerDatabaseGateway;
use App\Contracts\GameWorldDriver;
use App\Models\GameServer;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use App\Support\UniversalDrivers\ProfileValidationException;
use Illuminate\Database\Connection;

/** Executes release-owned profiles or verified connection-local snapshots. */
final class UniversalGameWorldDriver implements GameWorldDriver
{
    /**
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>|null  $snapshot
     */
    public function __construct(
        private readonly array $profile,
        private readonly GameServerDatabaseGateway $database,
        private readonly UniversalGameWorldReader $world,
        private readonly UniversalGameCharacterReader $characters,
        private readonly ?array $snapshot = null,
    ) {}

    public function capabilities(GameServer $server): array
    {
        return $this->run($server, fn (Connection $db): array => $this->world->capabilities($db, $this->profile));
    }

    public function ranking(GameServer $server, string $section, int $limit): array
    {
        return $this->run($server, fn (Connection $db): array => $this->world->ranking(
            $db, $this->profile, $section, $limit, $server->chronicle,
        ));
    }

    public function heroes(GameServer $server): array
    {
        return $this->run($server, fn (Connection $db): array => $this->world->heroes(
            $db, $this->profile, $server->chronicle,
        ));
    }

    public function castleOwners(GameServer $server): array
    {
        return $this->run($server, fn (Connection $db): array => $this->world->castleOwners($db, $this->profile));
    }

    public function charactersForAccount(GameServer $server, string $accountName, int $limit = 100): array
    {
        if ($this->profile['config']['characters']['owner']['identity'] !== 'login') {
            throw new ProfileValidationException('The CMS account contract requires character ownership by login.');
        }

        return $this->run($server, fn (Connection $db): array => $this->characters->charactersForAccount(
            $db, $this->profile, $accountName, $limit, $server->chronicle,
        ));
    }

    /**
     * @template TResult
     *
     * @param  callable(Connection): TResult  $callback
     * @return TResult
     */
    private function run(GameServer $server, callable $callback): mixed
    {
        return $this->database->run($server, function (Connection $database, ?array $snapshot = null) use ($callback): mixed {
            ConnectionProfileSnapshots::assertUnchanged($this->snapshot, $snapshot);

            return $callback($database);
        });
    }
}
