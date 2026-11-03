<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\GameServer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Wallet\WalletTransactionService;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use KaevCMS\Modules\Shop\Models\ShopCategory;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopTab;
use Tests\TestCase;

final class ShopActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRuntime::class)->bootModule(app(ModuleManager::class)->enable('shop'));
    }

    public function test_owner_can_enable_sales_and_player_can_buy_without_configuration_overrides(): void
    {
        $server = GameServer::factory()->create();
        $tab = ShopTab::query()->create(['game_server_id' => $server->id, 'name_ru' => 'Вещи', 'name_en' => 'Items', 'sort_order' => 0, 'is_active' => true]);
        $category = ShopCategory::query()->create(['tab_id' => $tab->id, 'name_ru' => 'Вещи', 'name_en' => 'Items', 'sort_order' => 0, 'is_active' => true]);
        $product = ShopProduct::query()->create(['category_id' => $category->id, 'type' => 'item', 'name_ru' => 'Адена', 'name_en' => 'Adena', 'price_coins' => 25, 'item_id' => 57, 'quantity' => 100, 'sort_order' => 0, 'is_active' => true]);
        $player = User::factory()->create();
        app(WalletTransactionService::class)->credit($player, 100, 'manual', 'activation-'.Str::uuid());
        $this->actingAs($player, 'web')->get('/modules/shop')->assertOk()->assertDontSee('name="request_uuid"', false);
        $this->actingAs(Admin::factory()->create(), 'admin');
        $this->put('/admin/extensions/shop/settings', ['sales_enabled' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->put('/admin/extensions/shop/servers/'.$server->id.'/settings', ['enabled' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($player, 'web')->get('/modules/shop')->assertOk()->assertSee('name="request_uuid"', false);
        $uuid = (string) Str::uuid();
        $this->post('/modules/shop/checkout', ['product_id' => $product->id, 'request_uuid' => $uuid, 'price_coins' => 1, 'quantity' => 999])->assertRedirect();
        $this->assertDatabaseHas('wallets', ['user_id' => $player->id, 'balance' => 75]);
        $this->assertDatabaseHas('reward_inventory_items', ['user_id' => $player->id, 'game_server_id' => $server->id, 'item_id' => 57, 'amount' => 100]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shop.settings_updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shop.server_settings_updated']);
        $this->put('/admin/extensions/shop/settings', ['sales_enabled' => 0])->assertRedirect();
        $this->post('/modules/shop/checkout', ['product_id' => $product->id, 'request_uuid' => (string) Str::uuid()])->assertForbidden();
        $this->get('/modules/shop/history')->assertOk()->assertSee('Адена');
        $this->assertDatabaseCount('module_shop_purchases', 1);
    }

    public function test_only_managers_can_change_validated_settings(): void
    {
        $server = GameServer::factory()->create();
        foreach ([Admin::factory()->auditor()->create(), Admin::factory()->editor()->create()] as $admin) {
            $this->actingAs($admin, 'admin')->put('/admin/extensions/shop/settings', ['sales_enabled' => 1])->assertForbidden();
            $this->put('/admin/extensions/shop/servers/'.$server->id.'/settings', ['enabled' => 1])->assertForbidden();
        }
        $this->actingAs(Admin::factory()->administrator()->create(), 'admin')->put('/admin/extensions/shop/settings', ['sales_enabled' => 'invalid'])->assertSessionHasErrors('sales_enabled');
        $this->put('/admin/extensions/shop/servers/'.$server->id.'/settings', ['enabled' => 'invalid'])->assertSessionHasErrors('enabled');
        $this->assertDatabaseHas('module_shop_settings', ['setting_key' => 'global', 'sales_enabled' => false]);
        $this->assertDatabaseCount('module_shop_server_settings', 0);
    }

    public function test_failed_settings_audit_rolls_back_activation(): void
    {
        $server = GameServer::factory()->create();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $this->mock(AuditLogger::class)->shouldReceive('success')->twice()->andReturn(null);
        $this->put('/admin/extensions/shop/settings', ['sales_enabled' => 1])->assertStatus(500);
        $this->put('/admin/extensions/shop/servers/'.$server->id.'/settings', ['enabled' => 1])->assertStatus(500);
        $this->assertDatabaseHas('module_shop_settings', ['setting_key' => 'global', 'sales_enabled' => false]);
        $this->assertDatabaseCount('module_shop_server_settings', 0);
    }
}
