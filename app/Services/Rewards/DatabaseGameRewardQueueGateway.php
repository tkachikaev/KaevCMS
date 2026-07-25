<?php

namespace App\Services\Rewards;

use App\Contracts\GameRewardQueueGateway;
use App\Contracts\GameServerDatabaseGateway;
use App\Models\GameServer;
use App\Support\Rewards\RewardQueueCapabilities;
use App\Support\Rewards\RewardQueuePayload;
use App\Support\Rewards\RewardQueueWriteResult;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Throwable;

final class DatabaseGameRewardQueueGateway implements GameRewardQueueGateway
{
    private const TABLE = 'kaev_reward_queue';

    /** @var list<string> */
    private const REQUIRED_COLUMNS = [
        'request_uuid',
        'line_number',
        'game_server_id',
        'source',
        'cms_user_id',
        'account_name',
        'character_id',
        'character_name',
        'item_id',
        'amount',
        'status',
        'attempts',
        'error_message',
        'created_at',
        'processing_started_at',
        'processed_at',
    ];

    public function __construct(private readonly GameServerDatabaseGateway $database) {}

    public function capabilities(GameServer $server): RewardQueueCapabilities
    {
        try {
            return $this->database->run($server, static function (Connection $connection): RewardQueueCapabilities {
                $schema = $connection->getSchemaBuilder();

                if (! $schema->hasTable(self::TABLE)) {
                    return RewardQueueCapabilities::unsupported('reward_queue_not_installed');
                }

                if (! $schema->hasColumns(self::TABLE, self::REQUIRED_COLUMNS)) {
                    return RewardQueueCapabilities::unsupported('reward_queue_schema_invalid');
                }

                return RewardQueueCapabilities::supported();
            });
        } catch (Throwable) {
            return RewardQueueCapabilities::unsupported('reward_queue_unavailable');
        }
    }

    public function enqueue(GameServer $server, RewardQueuePayload $payload): RewardQueueWriteResult
    {
        $rows = $this->rows($payload);
        if ($rows === []) {
            return RewardQueueWriteResult::failed('empty_reward_queue_payload');
        }

        try {
            return $this->database->run($server, function (Connection $connection) use ($payload, $rows): RewardQueueWriteResult {
                return $connection->transaction(function () use ($connection, $payload, $rows): RewardQueueWriteResult {
                    $existing = $this->existingRows($connection, $payload->requestUuid);
                    if ($existing->isNotEmpty()) {
                        return $this->verifyRows($existing, $rows);
                    }

                    $connection->table(self::TABLE)->insert($rows);

                    return $this->verifyRows(
                        $this->existingRows($connection, $payload->requestUuid),
                        $rows,
                    );
                }, 3);
            });
        } catch (Throwable) {
            return $this->confirmAfterError($server, $payload->requestUuid, $rows);
        }
    }

    /** @return list<array<string,mixed>> */
    private function rows(RewardQueuePayload $payload): array
    {
        $timestamp = now('UTC')->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($payload->items as $index => $item) {
            $itemId = $item['item_id'];
            $amount = $item['amount'];
            if ($itemId <= 0 || $amount <= 0) {
                return [];
            }

            $rows[] = [
                'request_uuid' => $payload->requestUuid,
                'line_number' => $index + 1,
                'game_server_id' => $payload->gameServerId,
                'source' => $payload->source,
                'cms_user_id' => $payload->cmsUserId,
                'account_name' => $payload->accountName,
                'character_id' => $payload->characterId,
                'character_name' => $payload->characterName,
                'item_id' => $itemId,
                'amount' => $amount,
                'status' => 'pending',
                'attempts' => 0,
                'error_message' => null,
                'created_at' => $timestamp,
                'processing_started_at' => null,
                'processed_at' => null,
            ];
        }

        return $rows;
    }

    /** @return Collection<int,array<string,mixed>> */
    private function existingRows(Connection $connection, string $requestUuid): Collection
    {
        $records = $connection->table(self::TABLE)
            ->where('request_uuid', $requestUuid)
            ->orderBy('line_number')
            ->get([
                'request_uuid',
                'line_number',
                'game_server_id',
                'source',
                'cms_user_id',
                'account_name',
                'character_id',
                'character_name',
                'item_id',
                'amount',
            ]);

        /** @var Collection<int,array<string,mixed>> $rows */
        $rows = $records
            ->map(static fn (object $record): array => get_object_vars($record))
            ->values();

        return $rows;
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $existing
     * @param  list<array<string,mixed>>  $expected
     */
    private function verifyRows(Collection $existing, array $expected): RewardQueueWriteResult
    {
        if ($existing->count() !== count($expected)) {
            return RewardQueueWriteResult::failed('reward_queue_payload_conflict');
        }

        foreach ($expected as $index => $row) {
            $actual = $existing->get($index);
            if (! is_array($actual) || ! $this->rowMatches($actual, $row)) {
                return RewardQueueWriteResult::failed('reward_queue_payload_conflict');
            }
        }

        return RewardQueueWriteResult::queued();
    }

    /** @param array<string,mixed> $expected */
    private function rowMatches(array $actual, array $expected): bool
    {
        return (string) ($actual['request_uuid'] ?? '') === (string) $expected['request_uuid']
            && (int) ($actual['line_number'] ?? 0) === (int) $expected['line_number']
            && (int) ($actual['game_server_id'] ?? 0) === (int) $expected['game_server_id']
            && (string) ($actual['source'] ?? '') === (string) $expected['source']
            && (int) ($actual['cms_user_id'] ?? 0) === (int) $expected['cms_user_id']
            && (string) ($actual['account_name'] ?? '') === (string) $expected['account_name']
            && (int) ($actual['character_id'] ?? 0) === (int) $expected['character_id']
            && (string) ($actual['character_name'] ?? '') === (string) $expected['character_name']
            && (int) ($actual['item_id'] ?? 0) === (int) $expected['item_id']
            && (int) ($actual['amount'] ?? 0) === (int) $expected['amount'];
    }

    /** @param list<array<string,mixed>> $expected */
    private function confirmAfterError(
        GameServer $server,
        string $requestUuid,
        array $expected,
    ): RewardQueueWriteResult {
        try {
            return $this->database->run($server, function (Connection $connection) use ($requestUuid, $expected): RewardQueueWriteResult {
                $rows = $this->existingRows($connection, $requestUuid);
                if ($rows->isEmpty()) {
                    return RewardQueueWriteResult::failed('reward_queue_write_failed');
                }

                return $this->verifyRows($rows, $expected);
            });
        } catch (Throwable) {
            return RewardQueueWriteResult::unknown();
        }
    }
}
