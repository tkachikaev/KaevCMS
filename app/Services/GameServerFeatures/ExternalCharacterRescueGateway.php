<?php

namespace App\Services\GameServerFeatures;

use App\Contracts\CharacterRescueGateway;
use App\Contracts\GameServerDatabaseGateway;
use App\Models\GameServer;
use App\Services\Servers\ServerDriverRegistry;
use App\Support\GameServerFeatures\CharacterRescueCapabilities;
use App\Support\GameServerFeatures\CharacterRescueWriteResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;

final class ExternalCharacterRescueGateway implements CharacterRescueGateway
{
    /** @var list<string> */
    private const REQUIRED_COLUMNS = [
        'charId',
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
        return $server->driver === ServerDriverRegistry::MOBIUS_DRIVER && $server->connectionConfigured();
    }

    public function capabilities(GameServer $server): CharacterRescueCapabilities
    {
        if (! $this->supports($server)) {
            return new CharacterRescueCapabilities(false, self::REQUIRED_COLUMNS);
        }

        return $this->database->run($server, function (Connection $connection): CharacterRescueCapabilities {
            $missing = $this->missingColumns($connection);

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
        if (! $this->supports($server)) {
            return new CharacterRescueWriteResult(CharacterRescueWriteResult::UNSUPPORTED);
        }

        return $this->database->run($server, function (Connection $connection) use (
            $accountLogin,
            $characterId,
            $target,
            $offlineBefore,
        ): CharacterRescueWriteResult {
            if ($this->missingColumns($connection) !== []) {
                return new CharacterRescueWriteResult(CharacterRescueWriteResult::UNSUPPORTED);
            }

            return $connection->transaction(function () use (
                $connection,
                $accountLogin,
                $characterId,
                $target,
                $offlineBefore,
            ): CharacterRescueWriteResult {
                $character = $connection->table('characters')
                    ->where('charId', $characterId)
                    ->where('account_name', $accountLogin)
                    ->where('deletetime', 0)
                    ->where('accesslevel', 0)
                    ->lockForUpdate()
                    ->first([
                        'charId',
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

                if ((int) $character->online !== 0) {
                    return new CharacterRescueWriteResult(
                        CharacterRescueWriteResult::ONLINE,
                        characterName: (string) $character->char_name,
                    );
                }

                $offlineBeforeMilliseconds = $offlineBefore->getTimestamp() * 1000;
                if ((int) $character->lastAccess > $offlineBeforeMilliseconds) {
                    return new CharacterRescueWriteResult(
                        CharacterRescueWriteResult::OFFLINE_DELAY,
                        characterName: (string) $character->char_name,
                    );
                }

                if (
                    (int) $character->x === $target['x']
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

                $updated = $connection->table('characters')
                    ->where('charId', $characterId)
                    ->where('account_name', $accountLogin)
                    ->where('online', 0)
                    ->where('deletetime', 0)
                    ->where('accesslevel', 0)
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
                    oldX: (int) $character->x,
                    oldY: (int) $character->y,
                    oldZ: (int) $character->z,
                );
            }, 1);
        });
    }

    /** @return list<string> */
    private function missingColumns(Connection $connection): array
    {
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable('characters')) {
            return self::REQUIRED_COLUMNS;
        }

        $missing = [];
        foreach (self::REQUIRED_COLUMNS as $column) {
            if (! $schema->hasColumn('characters', $column)) {
                $missing[] = $column;
            }
        }

        return $missing;
    }
}
