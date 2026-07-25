<?php

namespace App\Services\Rewards;

use App\Models\GameServer;
use App\Models\RewardInventoryGrant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Rewards\RewardGrantItem;
use Illuminate\Database\Eloquent\Model;
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

            $existing = RewardInventoryGrant::query()->where('grant_key', $grantKey)->first();
            if ($existing instanceof RewardInventoryGrant) {
                if ($existing->user_id !== $user->id || $existing->game_server_id !== $server->id) {
                    throw new InvalidArgumentException('Reward grant key is already used by another target.');
                }

                return $existing->load('items');
            }

            $grant = RewardInventoryGrant::query()->create([
                'grant_key' => $grantKey,
                'user_id' => $user->id,
                'game_server_id' => $server->id,
                'source_type' => $sourceType,
                'source_reference' => $this->nullableLimited($sourceReference, 190),
                'source_label' => $this->nullableLimited($sourceLabel, 190),
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
                    'status' => 'available',
                ]);
            }

            return $grant->load('items');
        }, 3);

        if ($grant->wasRecentlyCreated) {
            $this->auditLogger->success(
                category: 'reward',
                action: 'reward.inventory_granted',
                actor: $actor,
                target: $grant,
                details: [
                    'user_id' => $user->id,
                    'game_server_id' => $server->id,
                    'source_type' => $sourceType,
                    'source_reference' => $this->nullableLimited($sourceReference, 190),
                    'item_count' => $grant->items->count(),
                ],
            );
        }

        return $grant;
    }

    private function nullableLimited(?string $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, $limit, '') : null;
    }
}
