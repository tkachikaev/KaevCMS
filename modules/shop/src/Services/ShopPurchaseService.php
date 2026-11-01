<?php

namespace KaevCMS\Modules\Shop\Services;

use App\Contracts\WalletGateway;
use App\Models\GameServer;
use App\Models\ModuleState;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Rewards\RewardInventoryService;
use App\Support\Rewards\RewardGrantItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KaevCMS\Modules\Shop\Models\ShopCategory;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopPurchase;
use KaevCMS\Modules\Shop\Models\ShopTab;
use RuntimeException;

/**
 * Authenticated checkout engine. The wallet, inventory grant, snapshot and audit
 * must commit or roll back together. Caller supplies the signed-in player,
 * product ID and a stable request UUID for retries.
 */
final class ShopPurchaseService
{
    public function __construct(
        private readonly WalletGateway $wallet,
        private readonly RewardInventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    public function purchase(User $user, int $productId, string $requestUuid): ShopPurchase
    {
        $current = Auth::guard('web')->user();
        if (! $user->exists || ! $current instanceof User || ! $current->exists
            || ! $current->is_active || (int) $current->getKey() !== (int) $user->getKey()) {
            throw new AuthorizationException('Shop checkout requires the authenticated active player.');
        }
        if ($productId <= 0 || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $requestUuid) !== 1) {
            throw new InvalidArgumentException('Invalid shop purchase request.');
        }
        $requestUuid = strtolower($requestUuid);

        try {
            return DB::transaction(function () use ($user, $productId, $requestUuid): ShopPurchase {
                // Same lock order as WalletTransactionService: player first, then wallet.
                $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (! $lockedUser->is_active) {
                    throw new AuthorizationException('Shop checkout requires an active player.');
                }
                $existing = ShopPurchase::query()->where('request_uuid', $requestUuid)->first();
                if ($existing instanceof ShopPurchase) {
                    if ($existing->user_id !== (int) $user->id || $existing->product_id !== $productId) {
                        throw new InvalidArgumentException('Purchase UUID already belongs to another operation.');
                    }

                    // Replays return the committed snapshot even after the catalog changes
                    // or sales are switched off, without debiting or granting a second time.
                    return $existing;
                }

                // Fail closed: server-side configuration AND trusted Wallet source must
                // be enabled. New installations keep sales off in database settings.
                if (! ModuleState::query()->whereKey('shop')->where('enabled', true)->exists()
                    || config('wallet.enabled_sources.shop') !== true
                    || ! DB::table('module_shop_settings')->where('setting_key', 'global')->where('sales_enabled', true)->exists()) {
                    throw new AuthorizationException('Shop sales are disabled.');
                }

                $product = ShopProduct::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
                $category = ShopCategory::query()->whereKey($product->category_id)->lockForUpdate()->firstOrFail();
                $tab = ShopTab::query()->whereKey($category->tab_id)->lockForUpdate()->firstOrFail();
                $server = GameServer::query()->findOrFail($tab->game_server_id);
                if (! DB::table('module_shop_server_settings')->where('game_server_id', $server->id)
                    ->where('enabled', true)->exists()
                    || ! $product->is_active || ! $category->is_active || ! $tab->is_active) {
                    throw new AuthorizationException('Shop product is unavailable.');
                }

                $price = (int) $product->price_coins;
                if ($price < 1 || $price > 1000000 || ! in_array($product->type, ['item', 'bundle'], true)) {
                    throw new InvalidArgumentException('Shop product configuration is invalid.');
                }
                $items = $this->items($product);
                $snapshot = array_map(static fn (RewardGrantItem $item): array => [
                    'item_id' => $item->itemId,
                    'quantity' => $item->amount,
                ], $items);

                $operationKey = 'shop:'.$requestUuid;
                $debit = $this->wallet->debitForOwner($user, $price, $operationKey, 'Shop purchase '.$requestUuid);
                $grant = $this->inventory->grant(
                    $user, $server, $operationKey, 'shop', $items,
                    $requestUuid, $product->nameFor(), [
                        'product_id' => $product->id,
                        'product_type' => $product->type,
                        'price_coins' => $price,
                    ], $user,
                );
                $purchase = ShopPurchase::query()->create([
                    'request_uuid' => $requestUuid,
                    'user_id' => $user->id,
                    'game_server_id' => $server->id,
                    'product_id' => $product->id,
                    'product_type' => $product->type,
                    'product_name_ru' => $product->name_ru,
                    'product_name_en' => $product->name_en,
                    'price_coins' => $price,
                    'items_snapshot' => $snapshot,
                    'wallet_entry_id' => $debit->id,
                    'reward_inventory_grant_id' => $grant->id,
                ]);
                if ($this->audit->success('module', 'shop.purchase_completed', $user, $purchase, [
                    'game_server_id' => $server->id,
                    'product_id' => $product->id,
                    'price_coins' => $price,
                    'request_uuid' => $requestUuid,
                    'wallet_entry_id' => $debit->id,
                    'grant_id' => $grant->id,
                ]) === null) {
                    throw new RuntimeException('Shop purchase audit could not be recorded.');
                }

                return $purchase;
            }, 3);
        } catch (QueryException|InvalidArgumentException $exception) {
            // A concurrent buyer may commit after our first UUID lookup. Unique
            // conflicts and mismatched inventory-grant keys are both safe to
            // resolve only AFTER the entire failed transaction is rolled back.
            // Other errors are never swallowed without a committed purchase.
            $existing = ShopPurchase::query()->where('request_uuid', $requestUuid)->first();
            if ($existing instanceof ShopPurchase) {
                if ($existing->user_id !== (int) $user->id || $existing->product_id !== $productId) {
                    throw new InvalidArgumentException('Purchase UUID already belongs to another operation.', previous: $exception);
                }

                return $existing;
            }

            throw $exception;
        }
    }

    /** @return list<RewardGrantItem> */
    private function items(ShopProduct $product): array
    {
        if ($product->type === 'item') {
            $rows = [['item_id' => $product->item_id, 'quantity' => $product->quantity]];
        } else {
            // Bundle mutations lock the same parent row in AdminShopProductController.
            $rows = $product->bundleItems()->orderBy('item_id')->get(['item_id', 'quantity'])->toArray();
        }
        if ($rows === [] || count($rows) > 100) {
            throw new InvalidArgumentException('Shop product must contain 1 to 100 items.');
        }
        $items = [];
        $seen = [];
        foreach ($rows as $row) {
            $itemId = (int) $row['item_id'];
            $quantity = (int) $row['quantity'];
            if ($itemId < 1 || $itemId > 2147483647 || $quantity < 1 || $quantity > 2000000000
                || isset($seen[$itemId])) {
                throw new InvalidArgumentException('Shop product contains invalid or duplicate items.');
            }
            $seen[$itemId] = true;
            $items[] = new RewardGrantItem($itemId, $quantity);
        }

        return $items;
    }
}
