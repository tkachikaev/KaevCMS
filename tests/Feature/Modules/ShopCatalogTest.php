<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\GameServer;
use App\Services\AuditLogger;
use App\Support\Modules\ModuleGameServerDependencyRegistry;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\Shop\Models\ShopCategory;
use KaevCMS\Modules\Shop\Models\ShopTab;
use RuntimeException;
use Tests\TestCase;

final class ShopCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('shop');
        app(ModuleRuntime::class)->bootModule($module);
    }

    /** @return array<string, int|string> */
    private function values(array $overrides = []): array
    {
        return array_merge([
            'name_ru' => 'Оружие',
            'name_en' => 'Weapons',
            'sort_order' => 10,
            'is_active' => '1',
        ], $overrides);
    }

    public function test_manager_can_create_edit_and_delete_tabs_and_categories(): void
    {
        $server = GameServer::factory()->create();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->post('/admin/extensions/shop/servers/'.$server->id.'/tabs', $this->values())
            ->assertRedirect();
        $tab = ShopTab::query()->sole();
        $this->assertSame($server->id, $tab->game_server_id);
        $this->assertSame('Weapons', $tab->nameFor('en'));
        $this->assertSame('Оружие', $tab->nameFor('ru'));

        $this->post('/admin/extensions/shop/servers/'.$server->id.'/categories', $this->values([
            'tab_id' => $tab->id, 'name_ru' => 'Мечи', 'name_en' => 'Swords',
        ]))->assertRedirect();
        $category = ShopCategory::query()->sole();

        $this->put('/admin/extensions/shop/servers/'.$server->id.'/categories/'.$category->id, $this->values([
            'tab_id' => $tab->id, 'name_ru' => 'Топоры', 'name_en' => 'Axes', 'is_active' => '0',
        ]))->assertRedirect();
        $this->assertDatabaseHas('module_shop_categories', ['id' => $category->id, 'name_en' => 'Axes', 'is_active' => false]);

        $this->get('/admin/extensions/shop/servers/'.$server->id)->assertOk()
            ->assertSee('Топоры')->assertSee('Оружие')->assertSee('admin-card-row', false);

        $this->delete('/admin/extensions/shop/servers/'.$server->id.'/categories/'.$category->id, ['confirm' => '1'])
            ->assertRedirect();
        $this->delete('/admin/extensions/shop/servers/'.$server->id.'/tabs/'.$tab->id, ['confirm' => '1'])
            ->assertRedirect();
        $this->assertSame(0, ShopTab::query()->count());
        $this->assertSame(0, ShopCategory::query()->count());
        $this->assertSame(5, DB::table('audit_logs')->where('category', 'module')->where('action', 'like', 'shop.%')->count());
    }

    public function test_catalog_is_isolated_between_game_servers(): void
    {
        $a = GameServer::factory()->create();
        $b = GameServer::factory()->create();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $tab = ShopTab::query()->create($this->values() + ['game_server_id' => $a->id]);
        $category = ShopCategory::query()->create($this->values(['tab_id' => $tab->id]));

        $this->post('/admin/extensions/shop/servers/'.$b->id.'/categories', $this->values(['tab_id' => $tab->id]))
            ->assertSessionHasErrors('tab_id');
        $this->get('/admin/extensions/shop/servers/'.$b->id.'/tabs/'.$tab->id.'/edit')->assertNotFound();
        $this->put('/admin/extensions/shop/servers/'.$b->id.'/tabs/'.$tab->id, $this->values())->assertNotFound();
        $this->delete('/admin/extensions/shop/servers/'.$b->id.'/tabs/'.$tab->id, ['confirm' => '1'])->assertNotFound();
        $this->get('/admin/extensions/shop/servers/'.$b->id.'/categories/'.$category->id.'/edit')->assertNotFound();
        $this->put('/admin/extensions/shop/servers/'.$b->id.'/categories/'.$category->id, $this->values(['tab_id' => $tab->id]))->assertNotFound();
        $this->delete('/admin/extensions/shop/servers/'.$b->id.'/categories/'.$category->id, ['confirm' => '1'])->assertNotFound();
        $this->assertSame(1, ShopCategory::query()->count());
    }

    public function test_role_permissions_guard_write_operations(): void
    {
        $server = GameServer::factory()->create();
        $tab = ShopTab::query()->create($this->values() + ['game_server_id' => $server->id]);
        $url = '/admin/extensions/shop/servers/'.$server->id;

        $this->actingAs(Admin::factory()->auditor()->create(), 'admin')
            ->get($url)->assertOk()->assertDontSee('name="_form"', false);
        $this->get($url.'/tabs/'.$tab->id.'/edit')->assertOk()->assertSee(__('module-shop::messages.read_only'));
        $this->post($url.'/tabs', $this->values())->assertForbidden();
        $this->put($url.'/tabs/'.$tab->id, $this->values())->assertForbidden();
        $this->delete($url.'/tabs/'.$tab->id, ['confirm' => 1])->assertForbidden();
        $this->post($url.'/categories', $this->values(['tab_id' => $tab->id]))->assertForbidden();

        $this->actingAs(Admin::factory()->editor()->create(), 'admin')
            ->get($url)->assertForbidden();
        $this->get('/admin/extensions/shop')->assertForbidden();
        $this->assertSame(1, ShopTab::query()->count());
    }

    public function test_validation_and_child_deletion_protection(): void
    {
        $server = GameServer::factory()->create();
        $this->actingAs(Admin::factory()->administrator()->create(), 'admin');
        $url = '/admin/extensions/shop/servers/'.$server->id;
        $this->post($url.'/tabs', $this->values(['name_ru' => '  ', 'sort_order' => 10001]))
            ->assertSessionHasErrors();
        $this->assertSame(0, ShopTab::query()->count());

        $tab = ShopTab::query()->create($this->values() + ['game_server_id' => $server->id]);
        ShopCategory::query()->create($this->values(['tab_id' => $tab->id]));
        $this->delete($url.'/tabs/'.$tab->id, ['confirm' => 1])
            ->assertSessionHasErrors('confirm');
        $this->delete($url.'/tabs/'.$tab->id)->assertSessionHasErrors('confirm');
        $this->assertDatabaseHas('module_shop_tabs', ['id' => $tab->id]);
        $this->assertTrue(app(ModuleGameServerDependencyRegistry::class)->blocksDeletion($server));
    }

    public function test_failed_audit_rolls_back_catalog_create(): void
    {
        $server = GameServer::factory()->create();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $audit = $this->mock(AuditLogger::class);
        $audit->shouldReceive('success')->once()->andReturn(null);
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/extensions/shop/servers/'.$server->id.'/tabs', $this->values());
            $this->fail('Audit must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Shop administrative audit could not be recorded.', $exception->getMessage());
        }
        $this->assertSame(0, ShopTab::query()->count());
    }

    public function test_public_catalog_routes_are_registered_but_wallet_source_stays_disabled(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('modules.shop.index'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('modules.shop.checkout'));
        $this->assertSame(false, config('wallet.enabled_sources.shop'));
    }
}
