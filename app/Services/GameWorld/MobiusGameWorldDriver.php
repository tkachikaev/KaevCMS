<?php

namespace App\Services\GameWorld;

use App\Contracts\GameServerDatabaseGateway;
use App\Contracts\GameWorldDriver;
use App\Models\GameServer;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final class MobiusGameWorldDriver implements GameWorldDriver
{
    private const RANKINGS = ['level', 'pvp', 'pk', 'play_time'];

    public function __construct(
        private readonly GameServerDatabaseGateway $database,
        private readonly MobiusGameSchemaInspector $schemas,
    ) {}

    public function capabilities(GameServer $server): array
    {
        return $this->schemaProfile($server)->capabilities();
    }

    public function ranking(GameServer $server, string $section, int $limit): array
    {
        if (! in_array($section, self::RANKINGS, true)) {
            throw new InvalidArgumentException('Unsupported character ranking section.');
        }

        $profile = $this->schemaProfile($server);

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->database->run($server, function (Connection $connection) use ($profile, $section, $limit): array {
            $query = $this->charactersQuery($connection, $profile);

            match ($section) {
                'level' => $query->orderByDesc('characters.level')->orderByDesc('characters.exp'),
                'pvp' => $query->where('characters.pvpkills', '>', 0)->orderByDesc('characters.pvpkills')->orderByDesc('characters.level'),
                'pk' => $query->where('characters.pkkills', '>', 0)->orderByDesc('characters.pkkills')->orderByDesc('characters.level'),
                'play_time' => $query
                    ->where('characters.onlinetime', '>', 0)
                    ->orderByDesc('characters.onlinetime')
                    ->orderByDesc('characters.level'),
            };

            return $query
                ->orderBy('characters.char_name')
                ->limit($this->limit($limit))
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        });

        return $rows;
    }

    public function heroes(GameServer $server): array
    {
        $profile = $this->schemaProfile($server);
        if (! $profile->heroesAvailable) {
            return [];
        }

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->database->run($server, function (Connection $connection) use ($profile): array {
            return $this->charactersQuery($connection, $profile)
                ->join('heroes', 'heroes.charId', '=', 'characters.charId')
                ->addSelect([
                    'heroes.count as hero_count',
                    'heroes.played as hero_played',
                    'heroes.claimed as hero_claimed',
                ])
                ->orderByDesc('characters.level')
                ->orderBy('characters.char_name')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        });

        return $rows;
    }

    public function castleOwners(GameServer $server): array
    {
        $profile = $this->schemaProfile($server);
        if (! $profile->castlesAvailable) {
            return [];
        }

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->database->run($server, static function (Connection $connection): array {
            return $connection
                ->table('clan_data')
                ->join('castle', 'castle.id', '=', 'clan_data.hasCastle')
                ->leftJoin('characters as leader', 'leader.charId', '=', 'clan_data.leader_id')
                ->where('clan_data.hasCastle', '>', 0)
                ->select([
                    'castle.id as castle_id',
                    'castle.name as castle_name',
                    'clan_data.clan_id',
                    'clan_data.clan_name',
                    'clan_data.clan_level',
                    'clan_data.reputation_score',
                    'leader.char_name as leader_name',
                ])
                ->orderBy('castle.id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        });

        return $rows;
    }

    public function charactersForAccount(GameServer $server, string $accountName, int $limit = 100): array
    {
        $profile = $this->schemaProfile($server);

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->database->run($server, function (Connection $connection) use ($profile, $accountName, $limit): array {
            return $this->charactersQuery($connection, $profile)
                ->where('characters.account_name', $accountName)
                ->limit($this->limit($limit))
                ->orderByDesc('characters.level')
                ->orderBy('characters.char_name')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        });

        return $rows;
    }

    private function charactersQuery(Connection $connection, MobiusGameSchemaProfile $profile): Builder
    {
        return $connection
            ->table('characters')
            ->leftJoin('clan_data', 'clan_data.clan_id', '=', 'characters.clanid')
            ->where('characters.accesslevel', 0)
            ->where('characters.deletetime', 0)
            ->select([
                'characters.charId as id',
                'characters.char_name as name',
                'characters.level',
                'characters.classid as class_id',
                'characters.race',
                'characters.sex as gender',
                'characters.title',
                'characters.online',
                'characters.lastAccess as last_seen_at_ms',
                'characters.onlinetime as play_time_seconds',
                'characters.pvpkills as pvp_kills',
                'characters.pkkills as pk_kills',
                'characters.'.$profile->reputationColumn.' as reputation',
                'characters.nobless as noble',
                'characters.clanid as clan_id',
                'clan_data.clan_name',
            ]);
    }

    private function schemaProfile(GameServer $server): MobiusGameSchemaProfile
    {
        return $this->schemas->remembered($server, fn (): MobiusGameSchemaProfile => $this->inspectSchema($server));
    }

    private function inspectSchema(GameServer $server): MobiusGameSchemaProfile
    {
        return $this->database->run(
            $server,
            fn (Connection $connection): MobiusGameSchemaProfile => $this->schemas->inspect(
                $connection,
                (string) $server->chronicle,
            ),
        );
    }

    private function limit(int $limit): int
    {
        return min(max($limit, 1), 100);
    }
}
