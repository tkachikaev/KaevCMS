<?php

namespace Tests\Feature\Modules;

use App\Exceptions\WalletOperationException;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Wallet\WalletStatusService;
use App\Services\Wallet\WalletTransactionService;
use App\Support\Modules\ModuleGameServerDependencyRegistry;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use KaevCMS\Modules\Shop\Models\ShopBundleItem;
use KaevCMS\Modules\Shop\Models\ShopCategory;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopServerSetting;
use KaevCMS\Modules\Shop\Models\ShopSetting;
use KaevCMS\Modules\Shop\Models\ShopTab;
use KaevCMS\Modules\Shop\Services\ShopPurchaseService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class ShopPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('shop');
        app(ModuleRuntime::class)->bootModule($module);
    }

    /** @return array{User,GameServer,ShopProduct,ShopTab,ShopCategory} */
    private function catalog(string $type = 'item', int $balance = 100): array
    {
        $user = User::factory()->create();
        $server = GameServer::factory()->create();
        $tab = ShopTab::query()->create([
            'game_server_id' => $server->id, 'name_ru' => 'Снаряжение', 'name_en' => 'Gear',
            'sort_order' => 0, 'is_active' => true,
        ]);
        $category = ShopCategory::query()->create([
            'tab_id' => $tab->id, 'name_ru' => 'Оружие', 'name_en' => 'Weapons',
            'sort_order' => 0, 'is_active' => true,
        ]);
        $product = ShopProduct::query()->create([
            'category_id' => $category->id, 'type' => $type, 'name_ru' => 'Меч',
            'name_en' => 'Sword', 'price_coins' => 25,
            'item_id' => $type === 'item' ? 57 : null,
            'quantity' => $type === 'item' ? 3 : null,
            'sort_order' => 0, 'is_active' => true,
        ]);
        if ($balance > 0) {
            app(WalletTransactionService::class)->credit($user, $balance, 'manual', 'shop-test-'.Str::uuid());
        }
        $this->actingAs($user, 'web');

        return [$user, $server, $product, $tab, $category];
    }

    private function enableTestSales(GameServer $server): void
    {
        // Deliberate test-only override. Production source remains false.
        config()->set('wallet.enabled_sources.shop', true);
        ShopSetting::query()->where('setting_key', 'global')->update(['sales_enabled' => true]);
        ShopServerSetting::query()->create(['game_server_id' => $server->id, 'enabled' => true]);
    }

    public function test_checkout_route_rejects_a_disabled_trusted_source(): void
    {
        config()->set('wallet.enabled_sources.shop', false);
        [$user, $server, $product] = $this->catalog();
        $this->assertTrue(Route::has('modules.shop.checkout'));
        $this->assertFalse(config('wallet.enabled_sources.shop'));
        ShopSetting::query()->where('setting_key', 'global')->update(['sales_enabled' => true]);
        ShopServerSetting::query()->create(['game_server_id' => $server->id, 'enabled' => true]);

        $this->expectException(AuthorizationException::class);
        try {
            app(ShopPurchaseService::class)->purchase($user, $product->id, (string) Str::uuid());
        } finally {
            $this->assertDatabaseCount('module_shop_purchases', 0);
            $this->assertDatabaseCount('reward_inventory_grants', 0);
            $this->assertDatabaseMissing('wallet_entries', ['source' => 'shop']);
        }
    }

    public function test_successful_item_purchase_debits_and_grants_atomically_once(): void
    {
        [$user, $server, $product] = $this->catalog();
        $this->enableTestSales($server);
        $uuid = (string) Str::uuid();
        $service = app(ShopPurchaseService::class);
        $first = $service->purchase($user, $product->id, $uuid);
        $second = $service->purchase($user, $product->id, $uuid);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(25, $first->price_coins);
        $this->assertSame([['item_id' => 57, 'quantity' => 3]], $first->items_snapshot);
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 75]);
        $this->assertDatabaseHas('wallet_entries', ['id' => $first->wallet_entry_id, 'source' => 'shop', 'direction' => 'debit', 'amount' => 25]);
        $this->assertDatabaseHas('reward_inventory_grants', ['id' => $first->reward_inventory_grant_id, 'source_type' => 'shop']);
        $this->assertDatabaseHas('reward_inventory_items', ['user_id' => $user->id, 'game_server_id' => $server->id, 'item_id' => 57, 'amount' => 3]);
        $this->assertSame(1, DB::table('wallet_entries')->where('source', 'shop')->count());
        $this->assertDatabaseCount('module_shop_purchases', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shop.purchase_completed']);
    }

    public function test_bundle_snapshots_are_immutable_even_after_catalog_changes_and_deletion(): void
    {
        [$user, $server, $product] = $this->catalog('bundle');
        ShopBundleItem::query()->create(['product_id' => $product->id, 'item_id' => 57, 'quantity' => 2]);
        ShopBundleItem::query()->create(['product_id' => $product->id, 'item_id' => 4037, 'quantity' => 5]);
        $this->enableTestSales($server);
        $uuid = (string) Str::uuid();
        $service = app(ShopPurchaseService::class);
        $first = $service->purchase($user, $product->id, $uuid);
        $this->assertSame([
            ['item_id' => 57, 'quantity' => 2],
            ['item_id' => 4037, 'quantity' => 5],
        ], $first->items_snapshot);
        $this->assertDatabaseCount('reward_inventory_items', 2);
        $product->update(['price_coins' => 99, 'name_ru' => 'Новый меч', 'is_active' => false]);
        $product->delete();
        ShopSetting::query()->where('setting_key', 'global')->update(['sales_enabled' => false]);
        config()->set('wallet.enabled_sources.shop', false);
        $again = $service->purchase($user, $product->id, $uuid);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(25, $again->price_coins);
        $this->assertSame('Меч', $again->product_name_ru);
        $this->assertSame($first->items_snapshot, $again->items_snapshot);
        $this->assertDatabaseCount('module_shop_purchases', 1);
        $this->assertSame(1, DB::table('wallet_entries')->where('source', 'shop')->count());
    }

    public function test_uuid_reuse_with_other_product_or_other_player_is_rejected(): void
    {
        [$user, $server, $product] = $this->catalog();
        $other = $product->replicate();
        $other->save();
        $this->enableTestSales($server);
        $uuid = (string) Str::uuid();
        $service = app(ShopPurchaseService::class);
        $service->purchase($user, $product->id, $uuid);
        try {
            $service->purchase($user, $other->id, $uuid);
            $this->fail('UUID must not authorize a different product.');
        } catch (InvalidArgumentException) {
            // Expected: the original operation remains unchanged.
        }
        $different = User::factory()->create();
        $this->actingAs($different, 'web');
        $this->expectException(InvalidArgumentException::class);
        try {
            $service->purchase($different, $product->id, $uuid);
        } finally {
            $this->assertDatabaseCount('module_shop_purchases', 1);
            $this->assertSame(1, DB::table('wallet_entries')->where('source', 'shop')->count());
        }
    }

    public function test_disabled_server_tab_category_product_and_empty_bundle_never_debit(): void
    {
        [$user, $server, $product, $tab, $category] = $this->catalog();
        $this->enableTestSales($server);
        $service = app(ShopPurchaseService::class);
        $states = [
            [ShopServerSetting::class, ['enabled' => false]],
            [ShopTab::class, ['is_active' => false]],
            [ShopCategory::class, ['is_active' => false]],
            [ShopProduct::class, ['is_active' => false]],
        ];
        foreach ($states as [$type, $change]) {
            $record = match ($type) {
                ShopServerSetting::class => ShopServerSetting::query()->where('game_server_id', $server->id)->firstOrFail(),
                ShopTab::class => $tab,
                ShopCategory::class => $category,
                default => $product,
            };
            $record->update($change);
            try {
                $service->purchase($user, $product->id, (string) Str::uuid());
                $this->fail('An inactive product or section must not be purchasable.');
            } catch (AuthorizationException) {
                // Expected.
            }
            $record->update(array_fill_keys(array_keys($change), true));
        }
        $product->update(['type' => 'bundle', 'item_id' => null, 'quantity' => null]);
        $this->expectException(InvalidArgumentException::class);
        try {
            $service->purchase($user, $product->id, (string) Str::uuid());
        } finally {
            $this->assertDatabaseMissing('wallet_entries', ['source' => 'shop']);
            $this->assertDatabaseCount('module_shop_purchases', 0);
        }
    }

    public function test_insufficient_or_blocked_balance_cannot_create_purchase(): void
    {
        [$user, $server, $product] = $this->catalog(balance: 10);
        $this->enableTestSales($server);
        $service = app(ShopPurchaseService::class);
        try {
            $service->purchase($user, $product->id, (string) Str::uuid());
            $this->fail('Insufficient funds must reject purchase.');
        } catch (WalletOperationException $exception) {
            $this->assertSame('insufficient_funds', $exception->reason);
        }
        app(WalletTransactionService::class)->credit($user, 100, 'manual', 'shop-add-'.Str::uuid());
        app(WalletStatusService::class)->change(Admin::factory()->create(), $user, true,
            'shop-block-'.Str::uuid(), 'Blocked during shop test');
        try {
            $service->purchase($user, $product->id, (string) Str::uuid());
            $this->fail('Blocked wallets must reject purchase.');
        } catch (WalletOperationException $exception) {
            $this->assertSame('wallet_blocked', $exception->reason);
        }
        $this->assertDatabaseCount('module_shop_purchases', 0);
        $this->assertDatabaseMissing('wallet_entries', ['source' => 'shop']);
    }

    public function test_inventory_grant_error_rolls_back_wallet_debit(): void
    {
        [$user, $server, $product] = $this->catalog();
        $this->enableTestSales($server);
        $uuid = (string) Str::uuid();
        DB::table('reward_inventory_grants')->insert([
            'operation_uuid' => (string) Str::uuid(), 'grant_key' => 'shop:'.$uuid,
            'user_id' => $user->id, 'game_server_id' => $server->id,
            'source_type' => 'other', 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(InvalidArgumentException::class);
        try {
            app(ShopPurchaseService::class)->purchase($user, $product->id, $uuid);
        } finally {
            $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 100]);
            $this->assertDatabaseMissing('wallet_entries', ['source' => 'shop']);
            $this->assertDatabaseCount('module_shop_purchases', 0);
        }
    }

    public function test_shop_audit_failure_rolls_back_purchase_debit_and_inventory(): void
    {
        [$user, $server, $product] = $this->catalog();
        $this->enableTestSales($server);
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('success')->andReturnUsing(static function (...$args) {
            if (($args[1] ?? null) === 'shop.purchase_completed') {
                return null;
            }

            return (new AuditLogger)->success(...$args);
        });
        app()->instance(AuditLogger::class, $logger);
        $this->expectException(RuntimeException::class);
        try {
            app(ShopPurchaseService::class)->purchase($user, $product->id, (string) Str::uuid());
        } finally {
            $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 100]);
            $this->assertDatabaseMissing('wallet_entries', ['source' => 'shop']);
            $this->assertDatabaseCount('reward_inventory_grants', 0);
            $this->assertDatabaseCount('module_shop_purchases', 0);
        }
    }

    public function test_purchase_history_still_blocks_server_deletion_after_catalog_is_removed(): void
    {
        [$user, $server, $product, $tab, $category] = $this->catalog();
        $this->enableTestSales($server);
        app(ShopPurchaseService::class)->purchase($user, $product->id, (string) Str::uuid());
        $product->delete();
        $category->delete();
        $tab->delete();

        $this->assertTrue(app(ModuleGameServerDependencyRegistry::class)->blocksDeletion($server));
        $this->assertDatabaseCount('module_shop_purchases', 1);
    }

    public function test_service_rejects_other_player_or_inactive_account(): void
    {
        [$user, $server, $product] = $this->catalog();
        $this->enableTestSales($server);
        $other = User::factory()->create();
        $this->expectException(AuthorizationException::class);
        try {
            app(ShopPurchaseService::class)->purchase($other, $product->id, (string) Str::uuid());
        } finally {
            $this->assertDatabaseCount('module_shop_purchases', 0);
        }
    }
}
