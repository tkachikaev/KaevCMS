<?php

namespace App\Services\Rewards;

use App\Contracts\GameRewardQueueGateway;
use App\Models\RewardDelivery;
use App\Models\RewardDeliveryItem;
use App\Models\RewardInventoryItem;
use App\Services\AuditLogger;
use App\Support\Rewards\RewardQueueDiagnostic;
use App\Support\Rewards\RewardQueuePayload;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RewardDeliveryReconciler
{
    public function __construct(
        private readonly GameRewardQueueGateway $rewardQueue,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function reconcile(RewardDelivery|int $delivery): RewardDelivery
    {
        $deliveryId = $delivery instanceof RewardDelivery ? $delivery->id : $delivery;
        $delivery = RewardDelivery::query()
            ->with(['gameServer', 'items'])
            ->findOrFail($deliveryId);

        if (! $delivery->canReconcile()) {
            return $delivery;
        }

        try {
            $result = $this->rewardQueue->enqueue($delivery->gameServer, $this->payload($delivery));
        } catch (Throwable) {
            $this->markForReview($delivery->id, RewardQueueDiagnostic::WriteUnknown->value);

            return $this->findDelivery($delivery->id);
        }

        if ($result->isQueued()) {
            $this->markQueued($delivery->id);
        } elseif ($result->isFailed() && $result->failureCode !== RewardQueueDiagnostic::PayloadConflict->value) {
            $this->failAndRestore($delivery->id, $result->failureCode ?? RewardQueueDiagnostic::WriteFailed->value);
        } else {
            $this->markForReview($delivery->id, $result->failureCode ?? RewardQueueDiagnostic::WriteUnknown->value);
        }

        return $this->findDelivery($delivery->id);
    }

    private function findDelivery(int $deliveryId): RewardDelivery
    {
        return RewardDelivery::query()
            ->with(['gameServer', 'items'])
            ->findOrFail($deliveryId);
    }

    private function payload(RewardDelivery $delivery): RewardQueuePayload
    {
        return new RewardQueuePayload(
            requestUuid: $delivery->operation_uuid,
            gameServerId: $delivery->game_server_id,
            cmsUserId: $delivery->user_id,
            accountName: $delivery->account_login,
            characterId: $delivery->character_id,
            characterName: $delivery->character_name,
            items: $delivery->items->map(static fn (RewardDeliveryItem $item): array => [
                'item_id' => $item->item_id,
                'amount' => $item->amount,
            ])->values()->all(),
        );
    }

    private function markQueued(int $deliveryId): void
    {
        /** @var array{0:RewardDelivery,1:string}|null $transition */
        $transition = DB::transaction(function () use ($deliveryId): ?array {
            $delivery = RewardDelivery::query()->with('items')->lockForUpdate()->find($deliveryId);
            if (! $delivery instanceof RewardDelivery || ! $delivery->canTransitionTo(RewardDelivery::STATUS_QUEUED)) {
                return null;
            }

            $previousStatus = $delivery->status;
            $itemIds = $delivery->items->pluck('reward_inventory_item_id');
            RewardInventoryItem::query()
                ->whereIn('id', $itemIds)
                ->where('status', RewardInventoryItem::STATUS_RESERVED)
                ->update([
                    'status' => RewardInventoryItem::STATUS_TRANSFERRED,
                    'transferred_at' => now(),
                ]);

            $delivery->forceFill([
                'status' => RewardDelivery::STATUS_QUEUED,
                'failure_code' => null,
                'queued_at' => now(),
            ])->save();

            return [$delivery, $previousStatus];
        }, 3);

        if (is_array($transition)) {
            [$delivery, $previousStatus] = $transition;
            $this->auditLogger->system(
                category: 'reward',
                action: 'reward.queue_written',
                target: $delivery,
                details: $this->auditDetails($delivery, $previousStatus, RewardDelivery::STATUS_QUEUED),
            );
        }
    }

    private function failAndRestore(int $deliveryId, string $failureCode): void
    {
        /** @var array{0:RewardDelivery,1:string}|null $transition */
        $transition = DB::transaction(function () use ($deliveryId, $failureCode): ?array {
            $delivery = RewardDelivery::query()->with('items')->lockForUpdate()->find($deliveryId);
            if (! $delivery instanceof RewardDelivery || ! $delivery->canTransitionTo(RewardDelivery::STATUS_FAILED)) {
                return null;
            }

            $previousStatus = $delivery->status;
            $itemIds = $delivery->items->pluck('reward_inventory_item_id');
            RewardInventoryItem::query()
                ->whereIn('id', $itemIds)
                ->where('status', RewardInventoryItem::STATUS_RESERVED)
                ->update([
                    'status' => RewardInventoryItem::STATUS_AVAILABLE,
                    'transferred_at' => null,
                ]);

            $delivery->forceFill([
                'status' => RewardDelivery::STATUS_FAILED,
                'failure_code' => $failureCode,
                'queued_at' => null,
            ])->save();

            return [$delivery, $previousStatus];
        }, 3);

        if (is_array($transition)) {
            [$delivery, $previousStatus] = $transition;
            $this->auditLogger->system(
                category: 'reward',
                action: 'reward.queue_failed',
                target: $delivery,
                details: $this->auditDetails(
                    $delivery,
                    $previousStatus,
                    RewardDelivery::STATUS_FAILED,
                    $failureCode,
                ),
                result: 'failed',
            );
        }
    }

    private function markForReview(int $deliveryId, string $failureCode): void
    {
        /** @var array{0:RewardDelivery,1:string}|null $transition */
        $transition = DB::transaction(function () use ($deliveryId, $failureCode): ?array {
            $delivery = RewardDelivery::query()->with('items')->lockForUpdate()->find($deliveryId);
            if (! $delivery instanceof RewardDelivery || ! $delivery->canTransitionTo(RewardDelivery::STATUS_REVIEW)) {
                return null;
            }

            $previousStatus = $delivery->status;
            $delivery->forceFill([
                'status' => RewardDelivery::STATUS_REVIEW,
                'failure_code' => $failureCode,
                'queued_at' => null,
                'updated_at' => now(),
            ])->save();

            return [$delivery, $previousStatus];
        }, 3);

        if (is_array($transition)) {
            [$delivery, $previousStatus] = $transition;
            $this->auditLogger->system(
                category: 'reward',
                action: 'reward.queue_review_required',
                target: $delivery,
                details: $this->auditDetails(
                    $delivery,
                    $previousStatus,
                    RewardDelivery::STATUS_REVIEW,
                    $failureCode,
                ),
                result: 'failed',
            );
        }
    }

    /** @return array<string,mixed> */
    private function auditDetails(
        RewardDelivery $delivery,
        string $previousStatus,
        string $nextStatus,
        ?string $failureCode = null,
    ): array {
        return [
            'operation_uuid' => $delivery->operation_uuid,
            'user_id' => $delivery->user_id,
            'game_server_id' => $delivery->game_server_id,
            'character_id' => $delivery->character_id,
            'status_from' => $previousStatus,
            'status_to' => $nextStatus,
            'failure_code' => $failureCode,
            'item_count' => $delivery->items->count(),
            'items' => $delivery->items->map(static fn (RewardDeliveryItem $item): array => [
                'item_id' => $item->item_id,
                'amount' => $item->amount,
            ])->values()->all(),
        ];
    }
}
