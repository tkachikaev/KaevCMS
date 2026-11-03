<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\GameServer;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\Shop\Models\ShopServerSetting;
use Tests\TestCase;

final class ShopModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $module = app(ModuleManager::class)->enable('shop');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_shop_is_valid_and_installation_keeps_sales_disabled(): void
    {
        $module = app(ModuleManager::class)->inspect('shop');

        $this->assertTrue($module['valid'], implode(PHP_EOL, $module['errors']));
        $this->assertTrue($module['compatible'], implode(PHP_EOL, $module['errors']));
        $this->assertSame([], $module['pending_migrations']);
        $this->assertDatabaseHas('cms_modules', [
            'id' => 'shop',
            'version' => '1.0.6',
            'enabled' => true,
        ]);
        $this->assertTrue(Schema::hasTable('module_shop_settings'));
        $this->assertTrue(Schema::hasTable('module_shop_server_settings'));
        $this->assertTrue(Schema::hasTable('module_shop_tabs'));
        $this->assertTrue(Schema::hasTable('module_shop_categories'));
        $this->assertTrue(Schema::hasTable('module_shop_products'));
        $this->assertTrue(Schema::hasColumn('module_shop_products', 'section_id'));
        $this->assertTrue(Schema::hasColumn('module_shop_products', 'description_ru'));
        $this->assertTrue(Schema::hasColumn('module_shop_products', 'description_en'));
        $this->assertTrue(Schema::hasTable('module_shop_bundle_items'));
        $this->assertDatabaseHas('module_shop_settings', [
            'setting_key' => 'global',
            'sales_enabled' => false,
        ]);
        $this->assertTrue(Route::has('admin.module-pages.shop.index'));
        $this->assertTrue(Route::has('modules.shop.index'));
        $this->assertContains('admin.module-pages.shop.index', array_column(app(ModuleNavigationRegistry::class)->adminLinks(), 'route'));
        $this->assertNotContains('modules.shop.index', array_column(app(ModuleNavigationRegistry::class)->accountLinks(), 'route'));
        $this->assertTrue(app(ModuleAdminAccessRegistry::class)->isRegistered('admin.module-pages.shop.index'));
    }

    public function test_admin_overview_uses_existing_layout_and_permissions(): void
    {
        $owner = Admin::factory()->create();
        $administrator = Admin::factory()->administrator()->create();
        $auditor = Admin::factory()->auditor()->create();
        $editor = Admin::factory()->editor()->create();

        foreach ([$owner, $administrator, $auditor] as $admin) {
            $this->actingAs($admin, 'admin')
                ->get('/admin/extensions/shop')
                ->assertOk()
                ->assertSee(__('module-shop::messages.unavailable_title'))
                ->assertSee('admin-overview', false)
                ->assertViewHas('servers');
        }

        $this->actingAs($editor, 'admin')
            ->get('/admin/extensions/shop')
            ->assertForbidden();

        $this->assertDatabaseHas('module_shop_settings', [
            'setting_key' => 'global',
            'sales_enabled' => false,
        ]);
    }

    public function test_server_settings_are_disabled_by_default_and_tied_to_game_server(): void
    {
        $server = GameServer::factory()->create();
        ShopServerSetting::query()->create(['game_server_id' => $server->id]);

        $this->assertDatabaseHas('module_shop_server_settings', [
            'game_server_id' => $server->id,
            'enabled' => false,
        ]);

        $server->delete();
        $this->assertSame(0, DB::table('module_shop_server_settings')->count());
    }

    public function test_global_setting_cannot_enable_sales_without_trusted_wallet_source(): void
    {
        config()->set('wallet.enabled_sources.shop', false);
        DB::table('module_shop_settings')->where('setting_key', 'global')->update(['sales_enabled' => true]);
        $server = GameServer::factory()->create();
        ShopServerSetting::query()->create(['game_server_id' => $server->id, 'enabled' => true]);

        $this->assertTrue(Route::has('modules.shop.index'));
        $this->assertTrue(Route::has('modules.shop.checkout'));
        $this->assertFalse(config('wallet.enabled_sources.shop'));
        $this->actingAs(Admin::factory()->create(), 'admin')
            ->get('/admin/extensions/shop')
            ->assertOk()
            ->assertSee(__('module-shop::messages.sales_disabled'));
    }

    public function test_disabling_module_preserves_settings(): void
    {
        app(ModuleManager::class)->disable('shop');

        $this->assertTrue(Schema::hasTable('module_shop_settings'));
        $this->assertDatabaseHas('module_shop_settings', [
            'setting_key' => 'global',
            'sales_enabled' => false,
        ]);
        $this->assertDatabaseHas('cms_modules', ['id' => 'shop', 'enabled' => false]);
    }
}
