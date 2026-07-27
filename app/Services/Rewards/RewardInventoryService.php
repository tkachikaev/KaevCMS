<?php

namespace App\Services\Rewards;

use App\Models\GameServer;
use App\Models\RewardInventoryGrant;
use App\Models\RewardInventoryItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Rewards\RewardGrantItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RewardInventoryService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  list<mixed>  $items
     * @param  array<string,mixed>  $metadata
     */
    public function grant(
        User $user,
        GameServer $server,
        string $grantKey,
        string $sourceType,
        array $items,
        ?string $sourceReference = null,
        ?string $sourceLabel = null,
        array $metadata = [],
        Model|string|null $actor = null,
    ): RewardInventoryGrant {
        $grantKey = trim($grantKey);
        $sourceType = Str::lower(trim($sourceType));
        $sourceReference = $this->nullableLimited($sourceReference, 190);
        $sourceLabel = $this->nullableLimited($sourceLabel, 190);

        if ($grantKey === '' || mb_strlen($grantKey) > 190) {
            throw new InvalidArgumentException('Reward grant key is invalid.');
        }

        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/', $sourceType) !== 1) {
            throw new InvalidArgumentException('Reward source type is invalid.');
        }

        if ($items === [] || count($items) > 100) {
            throw new InvalidArgumentException('A reward grant must contain between 1 and 100 items.');
        }

        $validatedItems = [];
        foreach ($items as $item) {
            if (! $item instanceof RewardGrantItem) {
                throw new InvalidArgumentException('Reward grant items must be RewardGrantItem instances.');
            }

            $validatedItems[] = $item;
        }

        /** @var list<RewardGrantItem> $validatedItems */
        $items = $validatedItems;

        try {
            $grant = DB::transaction(function () use (
                $user,
                $server,
                $grantKey,
                $sourceType,
                $sourceReference,
                $sourceLabel,
                $metadata,
                $items,
            ): RewardInventoryGrant {
                User::query()->lockForUpdate()->findOrFail($user->id);

                $existing = RewardInventoryGrant::query()
                    ->where('grant_key', $grantKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof RewardInventoryGrant) {
                    return $this->assertGrantMatches(
                        $existing->load('items'),
                        $user,
                        $server,
                        $sourceType,
                        $sourceReference,
                        $items,
                    );
                }

                $grant = RewardInventoryGrant::query()->create([
                    'operation_uuid' => (string) Str::uuid(),
                    'grant_key' => $grantKey,
                    'user_id' => $user->id,
                    'game_server_id' => $server->id,
                    'source_type' => $sourceType,
                    'source_reference' => $sourceReference,
                    'source_label' => $sourceLabel,
                    'metadata' => $metadata === [] ? null : $metadata,
                    'granted_at' => now(),
                ]);

                foreach ($items as $item) {
                    $grant->items()->create([
                        'user_id' => $user->id,
                        'game_server_id' => $server->id,
                        'item_id' => $item->itemId,
                        'item_name' => $this->nullableLimited($item->name, 190),
                        'amount' => $item->amount,
                        'status' => RewardInventoryItem::STATUS_AVAILABLE,
                    ]);
                }

                return $grant->load('items');
            }, 3);
        } catch (QueryException $exception) {
            $existing = RewardInventoryGrant::query()
                ->where('grant_key', $grantKey)
                ->with('items')
                ->first();
            if (! $existing instanceof RewardInventoryGrant) {
                throw $exception;
            }

            $grant = $this->assertGrantMatches(
                $existing,
                $user,
                $server,
                $sourceType,
                $sourceReference,
                $items,
            );
        }

        if ($grant->wasRecentlyCreated) {
            $this->auditLogger->success(
                category: 'reward',
                action: 'reward.inventory_granted',
                actor: $actor,
                target: $grant,
                details: [
                    'operation_uuid' => $grant->operation_uuid,
                    'grant_key' => $grant->grant_key,
                    'user_id' => $user->id,
                    'game_server_id' => $server->id,
                    'source_type' => $sourceType,
                    'source_reference' => $sourceReference,
                    'item_count' => $grant->items->count(),
                    'items' => $grant->items->map(static fn (RewardInventoryItem $item): array => [
                        'item_id' => $item->item_id,
                        'amount' => $item->amount,
                    ])->values()->all(),
                ],
            );
        }

        return $grant;
    }

    /** @param list<RewardGrantItem> $items */
    private function assertGrantMatches(
        RewardInventoryGrant $grant,
        User $user,
        GameServer $server,
        string $sourceType,
        ?string $sourceReference,
        array $items,
    ): RewardInventoryGrant {
        if (
            $grant->user_id !== $user->id
            || $grant->game_server_id !== $server->id
            || $grant->source_type !== $sourceType
            || $grant->source_reference !== $sourceReference
            || $this->storedItems($grant) !== $this->requestedItems($items)
        ) {
            throw new InvalidArgumentException('Reward grant key is already used by a different reward operation.');
        }

        return $grant;
    }

    /** @return list<array{item_id:int,amount:int}> */
    private function storedItems(RewardInventoryGrant $grant): array
    {
        $items = $grant->items
            ->map(static fn (RewardInventoryItem $item): array => [
                'item_id' => $item->item_id,
                'amount' => $item->amount,
            ])
            ->values()
            ->all();

        usort($items, static fn (array $left, array $right): int => [
            $left['item_id'],
            $left['amount'],
        ] <=> [
            $right['item_id'],
            $right['amount'],
        ]);

        return $items;
    }

    /**
     * @param  list<RewardGrantItem>  $items
     * @return list<array{item_id:int,amount:int}>
     */
    private function requestedItems(array $items): array
    {
        $normalized = array_map(static fn (RewardGrantItem $item): array => [
            'item_id' => $item->itemId,
            'amount' => $item->amount,
        ], $items);

        usort($normalized, static fn (array $left, array $right): int => [
            $left['item_id'],
            $left['amount'],
        ] <=> [
            $right['item_id'],
            $right['amount'],
        ]);

        return $normalized;
    }

    private function nullableLimited(?string $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, $limit, '') : null;
    }
}
