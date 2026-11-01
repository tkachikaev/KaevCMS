<?php

namespace Tests\Feature\Modules;

use App\Models\GameServer;
use App\Models\User;
use App\Services\Wallet\WalletTransactionService;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KaevCMS\Modules\Shop\Models\ShopBundleItem;
use KaevCMS\Modules\Shop\Models\ShopCategory;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopServerSetting;
use KaevCMS\Modules\Shop\Models\ShopSetting;
use KaevCMS\Modules\Shop\Models\ShopTab;
use Tests\TestCase;

final class ShopStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('shop');
        app(ModuleRuntime::class)->bootModule($module);
    }

    /** @return array{GameServer,ShopTab,ShopCategory,ShopProduct} */
    private function published(string $name = 'Item', string $type = 'item'): array
    {
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
            'category_id' => $category->id, 'name_ru' => $name, 'name_en' => $name,
            'type' => $type, 'price_coins' => 25,
            'item_id' => $type === 'item' ? 57 : null,
            'quantity' => $type === 'item' ? 3 : null,
            'sort_order' => 0, 'is_active' => true,
        ]);
        if ($type === 'bundle') {
            ShopBundleItem::query()->create(['product_id' => $product->id, 'item_id' => 57, 'quantity' => 3]);
        }

        return [$server, $tab, $category, $product];
    }

    public function test_catalog_requires_login_and_uses_existing_account_components(): void
    {
        $this->published('Sword');
        $this->get('/modules/shop')->assertRedirect();
        $player = User::factory()->create();
        $this->actingAs($player, 'web')->get('/modules/shop')
            ->assertOk()->assertSee('Sword')->assertSee('reward-history-card', false)
            ->assertSee(__('module-shop::messages.account_sales_paused'))
            ->assertDontSee('name="request_uuid"', false);
        $this->assertNotContains('modules.shop.index', array_column(app(ModuleNavigationRegistry::class)->accountLinks(), 'route'));
    }

    public function test_server_navigation_uses_existing_tabs_and_cannot_select_another_servers_category(): void
    {
        [$server, $tab, $category, $product] = $this->published('First');
        [$otherServer, $otherTab, $otherCategory, $otherProduct] = $this->published('Second');
        $this->actingAs(User::factory()->create(), 'web')
            ->get('/modules/shop?server='.$server->id.'&tab='.$otherTab->id.'&category='.$otherCategory->id)
            ->assertOk()->assertSee('account-tabs', false)
            ->assertSee('First')->assertDontSee('Second');
    }

    public function test_catalog_filters_hidden_products_servers_tabs_and_categories(): void
    {
        [$visibleServer, $visibleTab, $visibleCategory, $visible] = $this->published('Visible');
        [$hiddenServer, $hiddenTab, $hiddenCategory, $hidden] = $this->published('Hidden');
        $visibleServer->update(['name' => 'Visible World']);
        $hiddenServer->update(['name' => 'Hidden World']);
        $hidden->update(['is_active' => false]);
        $this->actingAs(User::factory()->create(), 'web')->get('/modules/shop?server='.$hiddenServer->id)
            ->assertOk()->assertSee('Visible')->assertDontSee('Hidden')
            ->assertDontSee('Hidden World');
        $hidden->update(['is_active' => true]);
        $hiddenTab->update(['is_active' => false]);
        $this->get('/modules/shop')->assertDontSee('Hidden');
        $hiddenTab->update(['is_active' => true]);
        $hiddenCategory->update(['is_active' => false]);
        $this->get('/modules/shop')->assertDontSee('Hidden');
        $visible->update(['price_coins' => 0]);
        $this->get('/modules/shop')->assertDontSee('Visible');
    }

    public function test_each_visible_product_gets_a_distinct_checkout_uuid(): void
    {
        [$server, $tab, $category, $first] = $this->published('First item');
        $second = $first->replicate();
        $second->name_ru = 'Second item';
        $second->name_en = 'Second item';
        $second->save();
        ShopSetting::query()->where('setting_key', 'global')->update(['sales_enabled' => true]);
        ShopServerSetting::query()->create(['game_server_id' => $server->id, 'enabled' => true]);
        config()->set('wallet.enabled_sources.shop', true); // Test-only.
        $player = User::factory()->create();
        $response = $this->actingAs($player, 'web')->get('/modules/shop');
        $response->assertOk()->assertSee('First item')->assertSee('Second item');
        preg_match_all('/name="request_uuid" value="([^"]+)"/', $response->getContent(), $matches);
        $this->assertCount(2, $matches[1]);
        $this->assertCount(2, array_unique($matches[1]));
    }

    public function test_unconfigured_bundle_is_not_published(): void
    {
        [$server, $tab, $category, $bundle] = $this->published('Bundle', 'bundle');
        ShopBundleItem::query()->where('product_id', $bundle->id)->delete();
        $this->actingAs(User::factory()->create(), 'web')->get('/modules/shop')->assertOk()->assertDontSee('Bundle');
    }

    public function test_oversized_bundle_is_not_listed_for_purchase(): void
    {
        [$server, $tab, $category, $bundle] = $this->published('Oversized bundle', 'bundle');
        $now = now();
        $rows = [];
        // The fixture already contains item 57; these 100 entries make 101.
        for ($itemId = 58; $itemId <= 157; $itemId++) {
            $rows[] = [
                'product_id' => $bundle->id, 'item_id' => $itemId,
                'quantity' => 1, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        ShopBundleItem::query()->insert($rows);
        $this->assertSame(101, $bundle->bundleItems()->count());

        $this->actingAs(User::factory()->create(), 'web')
            ->get('/modules/shop')->assertOk()->assertDontSee('Oversized bundle');
    }

    public function test_manually_submitted_checkout_is_rejected_while_source_disabled(): void
    {
        [$server, $tab, $category, $product] = $this->published();
        $player = User::factory()->create();
        ShopSetting::query()->where('setting_key', 'global')->update(['sales_enabled' => true]);
        ShopServerSetting::query()->create(['game_server_id' => $server->id, 'enabled' => true]);
        $this->actingAs($player, 'web')->post('/modules/shop/checkout', [
            'product_id' => $product->id, 'request_uuid' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->assertDatabaseCount('module_shop_purchases', 0);
        $this->assertDatabaseCount('reward_inventory_grants', 0);
        $this->assertDatabaseMissing('wallet_entries', ['source' => 'shop']);
    }

    public function test_checkout_requires_valid_input_and_player_session(): void
    {
        $this->post('/modules/shop/checkout', ['product_id' => 1, 'request_uuid' => (string) Str::uuid()])->assertRedirect();
        $this->actingAs(User::factory()->create(), 'web')
            ->post('/modules/shop/checkout', ['product_id' => '-1', 'request_uuid' => 'fake'])
            ->assertSessionHasErrors(['product_id', 'request_uuid']);
    }

    public function test_checkout_deducts_once_and_redirects_to_inventory_message_when_explicitly_enabled_for_tests(): void
    {
        [$server, $tab, $category, $product] = $this->published('Sword');
        $player = User::factory()->create();
        app(WalletTransactionService::class)->credit($player, 100, 'manual', 'test-'.Str::uuid());
        ShopSetting::query()->where('setting_key', 'global')->update(['sales_enabled' => true]);
        ShopServerSetting::query()->create(['game_server_id' => $server->id, 'enabled' => true]);
        config()->set('wallet.enabled_sources.shop', true); // Test-only, production remains disabled.
        $this->assertContains('modules.shop.index', array_column(app(ModuleNavigationRegistry::class)->accountLinks(), 'route'));
        $uuid = (string) Str::uuid();
        $this->actingAs($player, 'web')
            ->post('/modules/shop/checkout', ['product_id' => $product->id, 'request_uuid' => $uuid])
            ->assertRedirect(route('modules.shop.index', ['server' => $server->id]))
            ->assertSessionHas('shop_purchased', true);
        $this->post('/modules/shop/checkout', ['product_id' => $product->id, 'request_uuid' => $uuid])
            ->assertRedirect(route('modules.shop.index', ['server' => $server->id]));
        $this->assertDatabaseHas('wallets', ['user_id' => $player->id, 'balance' => 75]);
        $this->assertDatabaseCount('module_shop_purchases', 1);
        $this->assertDatabaseCount('reward_inventory_grants', 1);
        $this->assertSame(1, DB::table('wallet_entries')->where('source', 'shop')->count());
    }
}
