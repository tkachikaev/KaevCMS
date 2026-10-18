<?php

namespace App\Services\GameWorld;

use App\Contracts\GameServerDatabaseGateway;
use App\Contracts\GameWorldDriver;
use App\Models\GameServer;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class RusacisGameWorldDriver implements GameWorldDriver
{
    private const RANKINGS = ['level', 'pvp', 'pk', 'play_time'];

    private const SCHEMA_CACHE_MINUTES = 5;

    // RUSaCis keeps these names in data/xml/castles.xml, not in the SQL table.
    private const CASTLE_NAMES = [
        1 => 'Gludio Castle', 2 => 'Dion Castle', 3 => 'Giran Castle',
        4 => 'Oren Castle', 5 => 'Aden Castle', 6 => 'Innadril Castle',
        7 => 'Goddard Castle', 8 => 'Rune Castle', 9 => 'Schuttgart Castle',
    ];

    public function __construct(
        private readonly GameServerDatabaseGateway $database,
        private readonly RusacisGameSchemaInspector $schemas,
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
                'play_time' => $query->where('characters.onlinetime', '>', 0)->orderByDesc('characters.onlinetime')->orderByDesc('characters.level'),
            };

            return $query->orderBy('characters.char_name')
                ->limit(min(max($limit, 1), 100))
                ->get()->map(static fn (object $row): array => (array) $row)->all();
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
                ->join('heroes', 'heroes.char_id', '=', 'characters.obj_Id')
                ->where('heroes.played', 1)
                ->where('heroes.active', 1)
                ->addSelect([
                    'heroes.count as hero_count',
                    'heroes.played as hero_played',
                    'heroes.active as hero_claimed',
                ])
                ->orderByDesc('characters.level')->orderBy('characters.char_name')
                ->get()->map(static fn (object $row): array => (array) $row)->all();
        });

        return $rows;
    }

    public function castleOwners(GameServer $server): array
    {
        if (! $this->schemaProfile($server)->castlesAvailable) {
            return [];
        }

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->database->run($server, static function (Connection $connection): array {
            return $connection->table('clan_data')
                ->join('castle', 'castle.id', '=', 'clan_data.hasCastle')
                ->leftJoin('characters as leader', 'leader.obj_Id', '=', 'clan_data.leader_id')
                ->where('clan_data.hasCastle', '>', 0)
                ->select([
                    'castle.id as castle_id',
                    'clan_data.clan_id',
                    'clan_data.clan_name',
                    'clan_data.clan_level',
                    'clan_data.reputation_score',
                    'leader.char_name as leader_name',
                ])
                ->orderBy('castle.id')
                ->get()->map(static fn (object $row): array => (array) $row)->all();
        });

        return array_map(static fn (array $row): array => array_merge($row, [
            'castle_name' => isset(self::CASTLE_NAMES[(int) $row['castle_id']])
                ? __(self::CASTLE_NAMES[(int) $row['castle_id']])
                : __('Castle #:id', ['id' => (int) $row['castle_id']]),
        ]), $rows);
    }

    public function charactersForAccount(GameServer $server, string $accountName, int $limit = 100): array
    {
        $profile = $this->schemaProfile($server);

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->database->run($server, function (Connection $connection) use ($profile, $accountName, $limit): array {
            return $this->charactersQuery($connection, $profile)
                ->where('characters.account_name', $accountName)
                ->orderByDesc('characters.level')->orderBy('characters.char_name')
                ->limit(min(max($limit, 1), 100))
                ->get()->map(static fn (object $row): array => (array) $row)->all();
        });

        return $rows;
    }

    private function charactersQuery(Connection $connection, RusacisGameSchemaProfile $profile): Builder
    {
        $heroExpression = 'characters.herountil > ?';
        if ($profile->heroesAvailable) {
            $heroExpression .= ' OR EXISTS (SELECT 1 FROM heroes WHERE heroes.char_id = characters.obj_Id AND heroes.played = 1 AND heroes.active = 1)';
        }

        return $connection->table('characters')
            ->leftJoin('clan_data', 'clan_data.clan_id', '=', 'characters.clanid')
            ->where('characters.accesslevel', 0)
            ->where(static fn (Builder $query): Builder => $query
                ->whereNull('characters.deletetime')
                ->orWhere('characters.deletetime', 0))
            ->select([
                'characters.obj_Id as id',
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
                'characters.karma as reputation',
                'characters.nobless as noble',
                'characters.clanid as clan_id',
                'clan_data.clan_name',
            ])
            ->selectRaw('(CASE WHEN '.$heroExpression.' THEN 1 ELSE 0 END) as hero', [now()->getTimestampMs()]);
    }

    private function schemaProfile(GameServer $server): RusacisGameSchemaProfile
    {
        if (! $server->exists) {
            return $this->inspectSchema($server);
        }
        $key = implode(':', [
            'rusacis-game-schema-v2',
            $server->id,
            $server->updated_at?->getTimestamp() ?? 0,
        ]);

        /** @var array{heroes_available:bool,castles_available:bool} $profile */
        $profile = Cache::remember(
            $key,
            now()->addMinutes(self::SCHEMA_CACHE_MINUTES),
            fn (): array => $this->inspectSchema($server)->toArray(),
        );

        return RusacisGameSchemaProfile::fromArray($profile);
    }

    private function inspectSchema(GameServer $server): RusacisGameSchemaProfile
    {
        return $this->database->run(
            $server,
            fn (Connection $connection): RusacisGameSchemaProfile => $this->schemas->inspect($connection),
        );
    }
}
