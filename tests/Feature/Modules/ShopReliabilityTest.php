<?php

namespace Tests\Feature\Modules;

use App\Exceptions\WalletOperationException;
use App\Models\GameServer;
use App\Models\ModuleState;
use App\Models\User;
use App\Services\Wallet\WalletTransactionService;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use KaevCMS\Modules\Shop\Models\ShopBundleItem;
use KaevCMS\Modules\Shop\Models\ShopCategory;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopPurchase;
use KaevCMS\Modules\Shop\Models\ShopServerSetting;
use KaevCMS\Modules\Shop\Models\ShopSetting;
use KaevCMS\Modules\Shop\Models\ShopTab;
use KaevCMS\Modules\Shop\Services\ShopPurchaseService;
use Tests\TestCase;

final class ShopReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('shop');
        app(ModuleRuntime::class)->bootModule($module);
    }

    /** @return array{User,GameServer,ShopProduct} */
    private function catalog(int $coins = 100, string $type = 'item'): array
    {
        $user = User::factory()->create();
        $server = GameServer::factory()->create();
        $tab = ShopTab::query()->create([
            'game_server_id' => $server->id, 'name_ru' => 'Вещи', 'name_en' => 'Items',
            'sort_order' => 0, 'is_active' => true,
        ]);
        $category = ShopCategory::query()->create([
            'tab_id' => $tab->id, 'name_ru' => 'Оружие', 'name_en' => 'Weapons',
            'sort_order' => 0, 'is_active' => true,
        ]);
        $product = ShopProduct::query()->create([
            'category_id' => $category->id, 'type' => $type,
            'name_ru' => 'Товар', 'name_en' => 'Product', 'price_coins' => 25,
            'item_id' => $type === 'item' ? 57 : null,
            'quantity' => $type === 'item' ? 3 : null,
            'sort_order' => 0, 'is_active' => true,
        ]);
        if ($coins > 0) {
            app(WalletTransactionService::class)->credit($user, $coins, 'manual', 'i7-seed-'.Str::uuid());
        }
        ShopSetting::query()->where('setting_key', 'global')->update(['sales_enabled' => true]);
        ShopServerSetting::query()->create(['game_server_id' => $server->id, 'enabled' => true]);
        // Test-only enablement; production config remains false.
        config()->set('wallet.enabled_sources.shop', true);
        $this->actingAs($user, 'web');

        return [$user, $server, $product];
    }

    public function test_stale_authenticated_user_is_rechecked_inside_the_locked_transaction(): void
    {
        [$user, $server, $product] = $this->catalog();
        // The session may still hold an active User object after an admin suspends it.
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $user->is_active);
        $this->expectException(AuthorizationException::class);

        try {
            app(ShopPurchaseService::class)->purchase($user, $product->id, (string) Str::uuid());
        } finally {
            $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 100]);
            $this->assertDatabaseMissing('wallet_entries', ['source' => 'shop']);
            $this->assertDatabaseCount('reward_inventory_grants', 0);
            $this->assertDatabaseCount('module_shop_purchases', 0);
        }
    }

    public function test_failed_purchase_uuid_can_be_safely_retried_after_balance_is_topped_up(): void
    {
        [$user, $server, $product] = $this->catalog(10);
        $uuid = (string) Str::uuid();
        $service = app(ShopPurchaseService::class);
        try {
            $service->purchase($user, $product->id, $uuid);
            $this->fail('Insufficient funds must reject the purchase.');
        } catch (WalletOperationException $exception) {
            $this->assertSame('insufficient_funds', $exception->reason);
        }

        $this->assertDatabaseMissing('wallet_entries', ['source' => 'shop']);
        $this->assertDatabaseCount('module_shop_purchases', 0);
        app(WalletTransactionService::class)->credit($user, 40, 'manual', 'i7-topup-'.Str::uuid());

        $first = $service->purchase($user, $product->id, $uuid);
        $replay = $service->purchase($user, $product->id, strtoupper($uuid));
        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 25]);
        $this->assertSame(1, DB::table('wallet_entries')->where('source', 'shop')->count());
        $this->assertDatabaseCount('reward_inventory_grants', 1);
        $this->assertDatabaseCount('module_shop_purchases', 1);
    }

    public function test_bad_bundle_item_cannot_debit_and_can_be_fixed_then_retried(): void
    {
        [$user, $server, $product] = $this->catalog(type: 'bundle');
        $item = ShopBundleItem::query()->create(['product_id' => $product->id, 'item_id' => 57, 'quantity' => 0]);
        $uuid = (string) Str::uuid();
        $service = app(ShopPurchaseService::class);
        try {
            $service->purchase($user, $product->id, $uuid);
            $this->fail('Invalid bundle item must reject the purchase.');
        } catch (InvalidArgumentException) {
            // Validation must precede the wallet debit.
        }
        $this->assertDatabaseMissing('wallet_entries', ['source' => 'shop']);
        $this->assertDatabaseCount('module_shop_purchases', 0);
        $item->update(['quantity' => 2]);
        $purchase = $service->purchase($user, $product->id, $uuid);
        $this->assertSame([['item_id' => 57, 'quantity' => 2]], $purchase->items_snapshot);
        $this->assertSame(1, DB::table('wallet_entries')->where('source', 'shop')->count());
    }

    public function test_module_disabled_cannot_debit_even_if_all_sales_switches_are_enabled(): void
    {
        [$user, $server, $product] = $this->catalog();
        ModuleState::query()->whereKey('shop')->update(['enabled' => false]);
        $this->expectException(AuthorizationException::class);
        try {
            app(ShopPurchaseService::class)->purchase($user, $product->id, (string) Str::uuid());
        } finally {
            $this->assertDatabaseMissing('wallet_entries', ['source' => 'shop']);
            $this->assertDatabaseCount('module_shop_purchases', 0);
        }
    }

    public function test_reused_uuid_with_a_different_user_or_product_cannot_reuse_a_purchase(): void
    {
        [$user, $server, $product] = $this->catalog();
        $uuid = (string) Str::uuid();
        $service = app(ShopPurchaseService::class);
        $first = $service->purchase($user, $product->id, $uuid);
        $otherProduct = $product->replicate();
        $otherProduct->save();
        try {
            $service->purchase($user, $otherProduct->id, $uuid);
            $this->fail('A UUID must not represent two different products.');
        } catch (InvalidArgumentException) {
            // Expected.
        }

        $otherUser = User::factory()->create();
        $this->actingAs($otherUser, 'web');
        try {
            $service->purchase($otherUser, $product->id, $uuid);
            $this->fail('A UUID must not represent purchases by two different users.');
        } catch (InvalidArgumentException) {
            // Expected.
        }
        $this->assertSame($first->id, ShopPurchase::query()->sole()->id);
        $this->assertSame(1, DB::table('wallet_entries')->where('source', 'shop')->count());
        $this->assertDatabaseCount('reward_inventory_grants', 1);
    }
}
