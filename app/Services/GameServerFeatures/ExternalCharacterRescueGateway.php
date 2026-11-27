<?php

namespace App\Services\GameServerFeatures;

use App\Contracts\CharacterRescueGateway;
use App\Contracts\GameServerDatabaseGateway;
use App\Models\GameServer;
use App\Services\Servers\ServerDriverRegistry;
use App\Support\GameServerFeatures\CharacterRescueCapabilities;
use App\Support\GameServerFeatures\CharacterRescueWriteResult;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final class ExternalCharacterRescueGateway implements CharacterRescueGateway
{
    /** @var list<string> */
    private const REQUIRED_COLUMNS = [
        'char_name',
        'account_name',
        'x',
        'y',
        'z',
        'online',
        'lastAccess',
        'deletetime',
        'accesslevel',
    ];

    public function __construct(private readonly GameServerDatabaseGateway $database) {}

    public function supports(GameServer $server): bool
    {
        return $this->supportedIdColumn($server) !== null;
    }

    public function capabilities(GameServer $server): CharacterRescueCapabilities
    {
        $idColumn = $this->supportedIdColumn($server);
        if ($idColumn === null) {
            return new CharacterRescueCapabilities(false, $this->requiredColumns($server->driver === ServerDriverRegistry::RUSACIS_DRIVER ? 'obj_Id' : 'charId'));
        }

        return $this->database->run($server, function (Connection $connection) use ($idColumn): CharacterRescueCapabilities {
            $missing = $this->missingColumns($connection, $idColumn);

            return new CharacterRescueCapabilities($missing === [], $missing);
        });
    }

    public function rescue(
        GameServer $server,
        string $accountLogin,
        int $characterId,
        array $target,
        CarbonImmutable $offlineBefore,
    ): CharacterRescueWriteResult {
        $idColumn = $this->supportedIdColumn($server);
        if ($idColumn === null) {
            return new CharacterRescueWriteResult(CharacterRescueWriteResult::UNSUPPORTED);
        }

        return $this->database->run($server, function (Connection $connection) use (
            $accountLogin,
            $characterId,
            $target,
            $offlineBefore,
            $idColumn,
        ): CharacterRescueWriteResult {
            if ($this->missingColumns($connection, $idColumn) !== []) {
                return new CharacterRescueWriteResult(CharacterRescueWriteResult::UNSUPPORTED);
            }

            return $connection->transaction(function () use (
                $connection,
                $accountLogin,
                $characterId,
                $target,
                $offlineBefore,
                $idColumn,
            ): CharacterRescueWriteResult {
                $query = $connection->table('characters')
                    ->where($idColumn, $characterId)
                    ->where('account_name', $accountLogin)
                    ->where('accesslevel', 0);

                if ($idColumn === 'obj_Id') {
                    // RUSaCis uses both NULL and zero for characters not marked for deletion.
                    $query->where(static fn (Builder $filter): Builder => $filter
                        ->whereNull('deletetime')
                        ->orWhere('deletetime', 0));
                } else {
                    $query->where('deletetime', 0);
                }

                $character = (clone $query)->lockForUpdate()
                    ->first([
                        'char_name',
                        'x',
                        'y',
                        'z',
                        'online',
                        'lastAccess',
                    ]);

                if ($character === null) {
                    return new CharacterRescueWriteResult(CharacterRescueWriteResult::NOT_FOUND);
                }

                if ($character->online === null || (int) $character->online !== 0) {
                    return new CharacterRescueWriteResult(
                        CharacterRescueWriteResult::ONLINE,
                        characterName: (string) $character->char_name,
                    );
                }

                $offlineBeforeMilliseconds = $offlineBefore->getTimestamp() * 1000;
                if ($character->lastAccess === null || (int) $character->lastAccess > $offlineBeforeMilliseconds) {
                    return new CharacterRescueWriteResult(
                        CharacterRescueWriteResult::OFFLINE_DELAY,
                        characterName: (string) $character->char_name,
                    );
                }

                if (
                    $character->x !== null && $character->y !== null && $character->z !== null
                    && (int) $character->x === $target['x']
                    && (int) $character->y === $target['y']
                    && (int) $character->z === $target['z']
                ) {
                    return new CharacterRescueWriteResult(
                        CharacterRescueWriteResult::SUCCESS,
                        characterName: (string) $character->char_name,
                        oldX: (int) $character->x,
                        oldY: (int) $character->y,
                        oldZ: (int) $character->z,
                    );
                }

                $updated = $query->where('online', 0)
                    ->where('lastAccess', '<=', $offlineBeforeMilliseconds)
                    ->update([
                        'x' => $target['x'],
                        'y' => $target['y'],
                        'z' => $target['z'],
                    ]);

                if ($updated !== 1) {
                    return new CharacterRescueWriteResult(
                        CharacterRescueWriteResult::STATE_CHANGED,
                        characterName: (string) $character->char_name,
                    );
                }

                return new CharacterRescueWriteResult(
                    CharacterRescueWriteResult::SUCCESS,
                    characterName: (string) $character->char_name,
                    oldX: $character->x === null ? null : (int) $character->x,
                    oldY: $character->y === null ? null : (int) $character->y,
                    oldZ: $character->z === null ? null : (int) $character->z,
                );
            }, 1);
        });
    }

    private function supportedIdColumn(GameServer $server): ?string
    {
        $idColumn = match ($server->driver) {
            ServerDriverRegistry::MOBIUS_DRIVER => 'charId',
            ServerDriverRegistry::RUSACIS_DRIVER => 'obj_Id',
            default => null,
        };

        // A verified JSON profile can change ownership and field semantics.
        if ($idColumn === null || ! $server->connectionConfigured() || app(ConnectionProfileSnapshots::class)->get($server) !== null) {
            return null;
        }

        return $idColumn;
    }

    /** @return list<string> */
    private function requiredColumns(string $idColumn): array
    {
        return [$idColumn, ...self::REQUIRED_COLUMNS];
    }

    /** @return list<string> */
    private function missingColumns(Connection $connection, string $idColumn): array
    {
        $schema = $connection->getSchemaBuilder();
        $required = $this->requiredColumns($idColumn);
        if (! $schema->hasTable('characters')) {
            return $required;
        }

        $missing = [];
        foreach ($required as $column) {
            if (! $schema->hasColumn('characters', $column)) {
                $missing[] = $column;
            }
        }

        return $missing;
    }
}
