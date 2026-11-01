<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\GameServer;
use App\Models\User;
use App\Services\Wallet\WalletTransactionService;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use KaevCMS\Modules\Shop\Models\ShopCategory;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopPurchase;
use KaevCMS\Modules\Shop\Models\ShopServerSetting;
use KaevCMS\Modules\Shop\Models\ShopSetting;
use KaevCMS\Modules\Shop\Models\ShopTab;
use KaevCMS\Modules\Shop\Services\ShopPurchaseService;
use Tests\TestCase;

final class ShopPurchaseHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('shop');
        app(ModuleRuntime::class)->bootModule($module);
    }

    /** @return array{User,GameServer,ShopProduct,ShopPurchase} */
    private function purchase(string $label = 'Historic sword'): array
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
            'category_id' => $category->id,
            'type' => 'item', 'name_ru' => $label, 'name_en' => $label,
            'price_coins' => 25, 'item_id' => 57, 'quantity' => 3,
            'sort_order' => 0, 'is_active' => true,
        ]);
        app(WalletTransactionService::class)->credit($user, 100, 'manual', 'history-'.Str::uuid());
        ShopSetting::query()->where('setting_key', 'global')->update(['sales_enabled' => true]);
        ShopServerSetting::query()->create(['game_server_id' => $server->id, 'enabled' => true]);
        config()->set('wallet.enabled_sources.shop', true); // Test-only setting.
        $this->actingAs($user, 'web');
        $purchase = app(ShopPurchaseService::class)->purchase($user, $product->id, (string) Str::uuid());

        return [$user, $server, $product, $purchase];
    }

    private function pauseSales(): void
    {
        config()->set('wallet.enabled_sources.shop', false);
        ShopSetting::query()->where('setting_key', 'global')->update(['sales_enabled' => false]);
    }

    public function test_player_history_is_private_and_available_even_after_sales_stop(): void
    {
        [$buyer, $server, $product, $purchase] = $this->purchase('My original sword');
        $this->pauseSales();
        $this->actingAs($buyer, 'web')->get('/modules/shop/history')
            ->assertOk()->assertSee('My original sword');
        $this->get('/modules/shop/history/'.$purchase->id)->assertOk()
            ->assertSee('My original sword')->assertSee((string) $purchase->price_coins);
        $this->assertContains('modules.shop.index', array_column(app(ModuleNavigationRegistry::class)->accountLinks(), 'route'));

        $other = User::factory()->create();
        $this->actingAs($other, 'web')->get('/modules/shop/history')
            ->assertOk()->assertDontSee('My original sword');
        $this->get('/modules/shop/history/'.$purchase->id)->assertNotFound();
        $this->get('/modules/shop/history/999999')->assertNotFound();
    }

    public function test_history_uses_saved_snapshot_after_catalog_product_changes_and_deletion(): void
    {
        [$buyer, $server, $product, $purchase] = $this->purchase('Old item name');
        $product->update(['name_ru' => 'New item name', 'name_en' => 'New item name', 'price_coins' => 100]);
        $product->delete();
        $this->pauseSales();
        $this->get('/modules/shop/history/'.$purchase->id)
            ->assertOk()->assertSee('Old item name')->assertDontSee('New item name')
            ->assertSee(__('module-shop::messages.account_bundle_entry', ['id' => format_number(57), 'count' => format_number(3)]));
        $this->assertSame(25, $purchase->fresh()->price_coins);
        $this->assertDatabaseHas('wallets', ['user_id' => $buyer->id, 'balance' => 75]);
        $this->assertDatabaseCount('module_shop_purchases', 1);
    }

    public function test_guest_cannot_read_history_or_receipt(): void
    {
        [$buyer, $server, $product, $purchase] = $this->purchase();
        auth('web')->logout();
        $this->get('/modules/shop/history')->assertRedirect();
        $this->get('/modules/shop/history/'.$purchase->id)->assertRedirect();
    }

    public function test_admin_journal_is_read_only_and_accessible_to_auditor_not_editor(): void
    {
        [$buyer, $server, $product, $purchase] = $this->purchase('Ledger item');
        $this->pauseSales();

        foreach ([Admin::factory()->create(), Admin::factory()->administrator()->create(), Admin::factory()->auditor()->create()] as $admin) {
            $this->actingAs($admin, 'admin')->get('/admin/extensions/shop/purchases')
                ->assertOk()->assertSee('Ledger item')->assertSee($buyer->name);
            $this->get('/admin/extensions/shop/purchases/'.$purchase->id)
                ->assertOk()->assertSee('Ledger item')->assertSee($purchase->request_uuid)
                ->assertSee((string) $purchase->wallet_entry_id);
        }
        $this->actingAs(Admin::factory()->editor()->create(), 'admin')
            ->get('/admin/extensions/shop/purchases')->assertForbidden();
        $this->get('/admin/extensions/shop/purchases/'.$purchase->id)->assertForbidden();
        $this->assertDatabaseCount('module_shop_purchases', 1);
    }

    public function test_admin_filters_by_user_server_and_date_and_validates_input(): void
    {
        [$buyer, $server, $product, $purchase] = $this->purchase('First order item');
        [$secondBuyer, $secondServer, $secondProduct, $secondPurchase] = $this->purchase('Second order item');
        $this->pauseSales();
        $this->actingAs(Admin::factory()->auditor()->create(), 'admin');
        $this->get('/admin/extensions/shop/purchases?user_id='.$buyer->id.'&server_id='.$server->id)
            ->assertOk()->assertSee('First order item')->assertDontSee('Second order item');
        $this->get('/admin/extensions/shop/purchases?user_id='.$secondBuyer->id.'&server_id='.$server->id)
            ->assertOk()->assertDontSee('First order item')->assertDontSee('Second order item');
        $date = $purchase->created_at->format('Y-m-d');
        $this->get('/admin/extensions/shop/purchases?from='.$date.'&to='.$date)
            ->assertOk()->assertSee('First order item')->assertSee('Second order item');
        $this->get('/admin/extensions/shop/purchases?from=bogus')->assertSessionHasErrors('from');
        $this->get('/admin/extensions/shop/purchases?from=2026-11-03&to=2026-11-02')->assertSessionHasErrors('to');
        $this->get('/admin/extensions/shop/purchases?user_id=-1')->assertSessionHasErrors('user_id');
        $this->get('/admin/extensions/shop/purchases/999999')->assertNotFound();
    }
}
