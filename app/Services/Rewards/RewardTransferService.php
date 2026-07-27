<?php

namespace App\Services\Rewards;

use App\Contracts\GameRewardQueueGateway;
use App\Exceptions\RewardTransferException;
use App\Models\GameServer;
use App\Models\RewardDelivery;
use App\Models\RewardDeliveryItem;
use App\Models\RewardInventoryItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Rewards\RewardTransferFailure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @phpstan-import-type RewardCharacter from RewardCharacterDirectory */
final class RewardTransferService
{
    public function __construct(
        private readonly GameRewardQueueGateway $rewardQueue,
        private readonly RewardCharacterDirectory $characters,
        private readonly RewardDeliveryReconciler $reconciler,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  list<int>  $inventoryItemIds
     */
    public function queue(
        User $user,
        GameServer $server,
        array $inventoryItemIds,
        int $characterId,
        string $requestToken,
    ): RewardDelivery {
        $inventoryItemIds = array_values(array_unique(array_map('intval', $inventoryItemIds)));
        sort($inventoryItemIds);
        if ($inventoryItemIds === [] || count($inventoryItemIds) > 50) {
            throw new RewardTransferException(RewardTransferFailure::InvalidSelection);
        }

        $existing = RewardDelivery::query()
            ->where('request_token', $requestToken)
            ->with('items')
            ->first();
        if ($existing instanceof RewardDelivery) {
            return $this->finishExisting(
                $this->assertDeliveryRequestMatches(
                    $existing,
                    $user,
                    $server,
                    $inventoryItemIds,
                    $characterId,
                ),
            );
        }

        $capabilities = $this->rewardQueue->capabilities($server);
        if (! $capabilities->supported) {
            throw new RewardTransferException(
                RewardTransferFailure::fromQueueReason($capabilities->reasonCode),
                $capabilities->reasonCode,
            );
        }

        $character = $this->characters->find($user, $server, $characterId);
        if ($character === null) {
            throw new RewardTransferException(RewardTransferFailure::CharacterNotOwned);
        }

        try {
            $delivery = DB::transaction(function () use (
                $user,
                $server,
                $inventoryItemIds,
                $character,
                $requestToken,
            ): RewardDelivery {
                User::query()->lockForUpdate()->findOrFail($user->id);

                $existing = RewardDelivery::query()
                    ->where('request_token', $requestToken)
                    ->with('items')
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof RewardDelivery) {
                    return $this->assertDeliveryMatches(
                        $existing,
                        $user,
                        $server,
                        $inventoryItemIds,
                        $character,
                    );
                }

                $items = RewardInventoryItem::query()
                    ->where('user_id', $user->id)
                    ->where('game_server_id', $server->id)
                    ->whereIn('id', $inventoryItemIds)
                    ->lockForUpdate()
                    ->get();

                if ($items->count() !== count($inventoryItemIds)
                    || $items->contains(static fn (RewardInventoryItem $item): bool => $item->status !== RewardInventoryItem::STATUS_AVAILABLE)) {
                    throw new RewardTransferException(RewardTransferFailure::ItemsUnavailable);
                }

                $delivery = RewardDelivery::query()->create([
                    'operation_uuid' => (string) Str::uuid(),
                    'request_token' => $requestToken,
                    'user_id' => $user->id,
                    'game_server_id' => $server->id,
                    'user_game_account_id' => $character['account_id'],
                    'character_id' => $character['id'],
                    'character_name' => $character['name'],
                    'account_login' => $character['account_login'],
                    'status' => RewardDelivery::STATUS_PENDING,
                    'requested_at' => now(),
                ]);

                foreach ($items as $item) {
                    $delivery->items()->create([
                        'reward_inventory_item_id' => $item->id,
                        'item_id' => $item->item_id,
                        'item_name' => $item->item_name,
                        'amount' => $item->amount,
                    ]);
                }

                RewardInventoryItem::query()
                    ->whereIn('id', $items->modelKeys())
                    ->update(['status' => RewardInventoryItem::STATUS_RESERVED]);

                return $delivery->load('items');
            }, 3);
        } catch (QueryException $exception) {
            $existing = RewardDelivery::query()
                ->where('request_token', $requestToken)
                ->with('items')
                ->first();
            if (! $existing instanceof RewardDelivery) {
                throw $exception;
            }

            $delivery = $this->assertDeliveryMatches($existing, $user, $server, $inventoryItemIds, $character);
        }

        if ($delivery->wasRecentlyCreated) {
            $this->auditLogger->success(
                category: 'reward',
                action: 'reward.transfer_requested',
                actor: $user,
                target: $delivery,
                details: $this->auditDetails($delivery, [
                    'status_from' => null,
                    'status_to' => RewardDelivery::STATUS_PENDING,
                ]),
            );
        }

        return $this->finishExisting($delivery);
    }

    private function finishExisting(RewardDelivery $delivery): RewardDelivery
    {
        if ($delivery->status === RewardDelivery::STATUS_FAILED) {
            throw new RewardTransferException(
                RewardTransferFailure::RewardQueueWriteFailed,
                $delivery->failure_code,
            );
        }

        if (in_array($delivery->status, [RewardDelivery::STATUS_QUEUED, RewardDelivery::STATUS_REVIEW], true)) {
            return $delivery;
        }

        $delivery = $this->reconciler->reconcile($delivery);

        if ($delivery->status === RewardDelivery::STATUS_FAILED) {
            throw new RewardTransferException(
                RewardTransferFailure::RewardQueueWriteFailed,
                $delivery->failure_code,
            );
        }

        return $delivery;
    }

    /**
     * @param  list<int>  $inventoryItemIds
     * @param  RewardCharacter  $character
     */
    private function assertDeliveryMatches(
        RewardDelivery $delivery,
        User $user,
        GameServer $server,
        array $inventoryItemIds,
        array $character,
    ): RewardDelivery {
        $this->assertDeliveryRequestMatches(
            $delivery,
            $user,
            $server,
            $inventoryItemIds,
            $character['id'],
        );

        if (
            $delivery->user_game_account_id !== $character['account_id']
            || $delivery->account_login !== $character['account_login']
        ) {
            throw new RewardTransferException(RewardTransferFailure::RequestTokenConflict);
        }

        return $delivery;
    }

    /** @param list<int> $inventoryItemIds */
    private function assertDeliveryRequestMatches(
        RewardDelivery $delivery,
        User $user,
        GameServer $server,
        array $inventoryItemIds,
        int $characterId,
    ): RewardDelivery {
        $storedItemIds = $delivery->items
            ->map(static fn (RewardDeliveryItem $item): int => $item->reward_inventory_item_id)
            ->sort()
            ->values()
            ->all();

        if (
            $delivery->user_id !== $user->id
            || $delivery->game_server_id !== $server->id
            || $delivery->character_id !== $characterId
            || $storedItemIds !== $inventoryItemIds
        ) {
            throw new RewardTransferException(RewardTransferFailure::RequestTokenConflict);
        }

        return $delivery;
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function auditDetails(RewardDelivery $delivery, array $extra = []): array
    {
        return array_merge([
            'operation_uuid' => $delivery->operation_uuid,
            'user_id' => $delivery->user_id,
            'game_server_id' => $delivery->game_server_id,
            'character_id' => $delivery->character_id,
            'item_count' => $delivery->items->count(),
            'items' => $delivery->items->map(static fn (RewardDeliveryItem $item): array => [
                'item_id' => $item->item_id,
                'amount' => $item->amount,
            ])->values()->all(),
        ], $extra);
    }
}
