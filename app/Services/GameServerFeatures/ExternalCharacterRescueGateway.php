<?php

namespace App\Services\GameServerFeatures;

use App\Contracts\CharacterRescueGateway;
use App\Contracts\GameAccountGateway;
use App\Contracts\GameServerDatabaseGateway;
use App\Models\GameServer;
use App\Support\GameServerFeatures\CharacterRescueCapabilities;
use App\Support\GameServerFeatures\CharacterRescueWriteResult;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\CharacterRescueMapping;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

final class ExternalCharacterRescueGateway implements CharacterRescueGateway
{
    public function __construct(private readonly GameServerDatabaseGateway $database) {}

    public function supports(GameServer $server): bool
    {
        return $this->mapping($server) !== null;
    }

    public function capabilities(GameServer $server): CharacterRescueCapabilities
    {
        $operation = $this->mapping($server);
        if ($operation === null) {
            return new CharacterRescueCapabilities(false);
        }

        return $this->database->run($server, static function (Connection $connection, ?array $active = null) use ($operation): CharacterRescueCapabilities {
            ConnectionProfileSnapshots::assertUnchanged($operation['snapshot'], $active);

            return $operation['mapping']->capabilities($connection);
        });
    }

    public function rescue(
        GameServer $server,
        string $accountLogin,
        int $characterId,
        array $target,
        CarbonImmutable $offlineBefore,
    ): CharacterRescueWriteResult {
        $operation = $this->mapping($server);
        if ($operation === null) {
            return new CharacterRescueWriteResult(CharacterRescueWriteResult::UNSUPPORTED);
        }
        $mapping = $operation['mapping'];

        return $this->database->run($server, function (Connection $connection, ?array $active = null) use (
            $operation,
            $accountLogin,
            $characterId,
            $target,
            $offlineBefore,
            $mapping,
        ): CharacterRescueWriteResult {
            ConnectionProfileSnapshots::assertUnchanged($operation['snapshot'], $active);
            if (! $mapping->capabilities($connection)->supported) {
                return new CharacterRescueWriteResult(CharacterRescueWriteResult::UNSUPPORTED);
            }

            return $connection->transaction(function () use (
                $connection,
                $accountLogin,
                $characterId,
                $target,
                $offlineBefore,
                $mapping,
            ): CharacterRescueWriteResult {
                $query = $mapping->characterQuery($connection, $accountLogin, $characterId);
                $columns = $mapping->columns;
                $character = (clone $query)->lockForUpdate()->first([
                    $columns['name'].' as char_name',
                    $columns['x'].' as x',
                    $columns['y'].' as y',
                    $columns['z'].' as z',
                    $columns['online'].' as online',
                    $columns['last_seen_at_ms'].' as lastAccess',
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

                $updated = $query->where($columns['online'], 0)
                    ->where($columns['last_seen_at_ms'], '<=', $offlineBeforeMilliseconds)
                    ->update([
                        $columns['x'] => $target['x'],
                        $columns['y'] => $target['y'],
                        $columns['z'] => $target['z'],
                    ]);

                if ($updated > 1) {
                    throw new RuntimeException('Character rescue matched multiple rows; transaction rolled back.');
                }
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

    /** @return array{mapping:CharacterRescueMapping,snapshot:array<string,mixed>|null}|null */
    private function mapping(GameServer $server): ?array
    {
        if (! $server->connectionConfigured()) {
            return null;
        }
        try {
            $snapshot = app(ConnectionProfileSnapshots::class)->get($server);
            if ($snapshot !== null && ! app(GameAccountGateway::class)->supportsGameServer($server)) {
                return null;
            }
            $profile = $snapshot ?? (new BundledProfileCatalog)->all()['game:'.$server->driver] ?? null;
            $mapping = $profile !== null ? CharacterRescueMapping::fromProfile($profile) : null;

            return $mapping === null ? null : ['mapping' => $mapping, 'snapshot' => $snapshot];
        } catch (RuntimeException) {
            return null;
        }
    }
}
