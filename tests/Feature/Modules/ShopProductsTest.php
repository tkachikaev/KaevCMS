<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\GameServer;
use App\Services\AuditLogger;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\Shop\Models\ShopBundleItem;
use KaevCMS\Modules\Shop\Models\ShopCategory;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopTab;
use RuntimeException;
use Tests\TestCase;

final class ShopProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('shop');
        app(ModuleRuntime::class)->bootModule($module);
    }

    /** @return array{0:GameServer,1:ShopCategory} */
    private function catalog(): array
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

        return [$server, $category];
    }

    /** @return array<string, string|int> */
    private function payload(int $categoryId, string $type = 'item'): array
    {
        $fields = [
            'category_id' => $categoryId, 'name_ru' => 'Меч', 'name_en' => 'Sword',
            'price_coins' => 75, 'sort_order' => 10, 'is_active' => 1,
        ];

        return $type === 'item'
            ? $fields + ['type' => 'item', 'item_id' => 57, 'quantity' => 3]
            : $fields + ['type' => 'bundle'];
    }

    public function test_admin_can_create_and_edit_single_item_with_integer_price(): void
    {
        [$server, $category] = $this->catalog();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $this->post($base.'/products', $this->payload($category->id))->assertRedirect();
        $product = ShopProduct::query()->sole();
        $this->assertSame('item', $product->type);
        $this->assertSame(75, $product->price_coins);
        $this->assertSame(57, $product->item_id);
        $this->assertSame(3, $product->quantity);

        $this->put($base.'/products/'.$product->id, array_replace($this->payload($category->id), ['type' => 'bundle']))
            ->assertRedirect();
        $this->assertSame('item', $product->fresh()->type);
        $this->get($base)->assertOk()->assertSee('Меч')->assertSee('75');
        $this->get($base.'/products/'.$product->id.'/edit')->assertOk()->assertSee('price_coins');
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'like', 'shop.product_%')->count());
    }

    public function test_bundle_can_be_configured_and_duplicate_ids_are_rejected(): void
    {
        [$server, $category] = $this->catalog();
        $this->actingAs(Admin::factory()->administrator()->create(), 'admin');
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $this->post($base.'/products', $this->payload($category->id, 'bundle'))->assertRedirect();
        $bundle = ShopProduct::query()->sole();
        $this->assertSame('bundle', $bundle->type);
        $this->assertNull($bundle->item_id);
        $this->assertNull($bundle->quantity);
        $route = $base.'/products/'.$bundle->id.'/bundle-items';
        $this->post($route, ['item_id' => 57, 'quantity' => 2])->assertRedirect();
        $this->post($route, ['item_id' => 58, 'quantity' => 3])->assertRedirect();
        $this->post($route, ['item_id' => 57, 'quantity' => 1])->assertSessionHasErrors('item_id');
        $this->assertSame(2, ShopBundleItem::query()->count());
        $item = ShopBundleItem::query()->where('item_id', 57)->sole();
        $this->delete($route.'/'.$item->id, ['confirm' => 1])->assertRedirect();
        $this->assertSame(1, ShopBundleItem::query()->count());
        $this->get($base.'/products/'.$bundle->id.'/edit')->assertOk()->assertSee('58');
        $this->delete($base.'/products/'.$bundle->id, ['confirm' => 1])->assertRedirect();
        $this->assertSame(0, ShopProduct::query()->count());
        $this->assertSame(0, ShopBundleItem::query()->count());
    }

    public function test_bundle_cannot_be_configured_with_more_than_one_hundred_items(): void
    {
        [$server, $category] = $this->catalog();
        $this->actingAs(Admin::factory()->administrator()->create(), 'admin');
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $this->post($base.'/products', $this->payload($category->id, 'bundle'))->assertRedirect();
        $bundle = ShopProduct::query()->sole();

        $now = now();
        $rows = [];
        for ($itemId = 1; $itemId <= 100; $itemId++) {
            $rows[] = [
                'product_id' => $bundle->id, 'item_id' => $itemId,
                'quantity' => 1, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        ShopBundleItem::query()->insert($rows);
        $route = $base.'/products/'.$bundle->id.'/bundle-items';

        $this->post($route, ['item_id' => 101, 'quantity' => 1])
            ->assertSessionHasErrors('item_id');
        $this->assertSame(100, $bundle->bundleItems()->count());
        $this->assertTrue(ShopProduct::query()->published()->whereKey($bundle->id)->exists());
        $this->get($base.'/products/'.$bundle->id.'/edit')
            ->assertOk()->assertSee(__('module-shop::messages.bundle_item_limit_reached'));

        // An already-corrupt legacy configuration must not show a buy button.
        ShopBundleItem::query()->create(['product_id' => $bundle->id, 'item_id' => 101, 'quantity' => 1]);
        $this->assertFalse(ShopProduct::query()->published()->whereKey($bundle->id)->exists());
    }

    public function test_server_isolation_bad_data_and_deletion_guard(): void
    {
        [$server, $category] = $this->catalog();
        [$other, $otherCategory] = $this->catalog();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $this->post($base.'/products', $this->payload($otherCategory->id))->assertSessionHasErrors('category_id');
        $this->post($base.'/products', $this->payload($category->id) + ['type' => 'item'])->assertRedirect();
        $product = ShopProduct::query()->sole();
        $this->post($base.'/products', array_replace($this->payload($category->id), ['price_coins' => '1.50']))->assertSessionHasErrors('price_coins');
        $this->post($base.'/products', array_replace($this->payload($category->id), ['quantity' => 2000000001]))->assertSessionHasErrors('quantity');
        $this->put($base.'/products/'.$product->id, array_replace($this->payload($category->id), ['category_id' => $otherCategory->id]))->assertSessionHasErrors('category_id');
        $this->get('/admin/extensions/shop/servers/'.$other->id.'/products/'.$product->id.'/edit')->assertNotFound();
        $this->put('/admin/extensions/shop/servers/'.$other->id.'/products/'.$product->id, $this->payload($otherCategory->id))->assertNotFound();
        $this->delete('/admin/extensions/shop/servers/'.$other->id.'/products/'.$product->id, ['confirm' => 1])->assertNotFound();
        $this->delete($base.'/categories/'.$category->id, ['confirm' => 1])->assertSessionHasErrors('confirm');
        $this->assertDatabaseHas('module_shop_products', ['id' => $product->id, 'category_id' => $category->id]);
        $this->assertDatabaseHas('module_shop_settings', ['setting_key' => 'global', 'sales_enabled' => false]);
    }

    public function test_bundle_items_cannot_be_managed_through_another_server_or_item(): void
    {
        [$server, $category] = $this->catalog();
        [$other] = $this->catalog();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $this->post($base.'/products', $this->payload($category->id, 'bundle'))->assertRedirect();
        $bundle = ShopProduct::query()->sole();
        $this->post($base.'/products/'.$bundle->id.'/bundle-items', ['item_id' => 57, 'quantity' => 2])->assertRedirect();
        $item = ShopBundleItem::query()->sole();
        $foreign = '/admin/extensions/shop/servers/'.$other->id.'/products/'.$bundle->id.'/bundle-items';
        $this->post($foreign, ['item_id' => 58, 'quantity' => 1])->assertNotFound();
        $this->delete($foreign.'/'.$item->id, ['confirm' => 1])->assertNotFound();
        $this->assertSame(1, ShopBundleItem::query()->count());
    }

    public function test_auditor_cannot_write_and_editor_cannot_read(): void
    {
        [$server, $category] = $this->catalog();
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $this->actingAs(Admin::factory()->auditor()->create(), 'admin');
        $this->get($base.'/products/create')->assertOk()->assertSee(__('module-shop::messages.read_only'));
        $this->post($base.'/products', $this->payload($category->id))->assertForbidden();
        $this->actingAs(Admin::factory()->editor()->create(), 'admin');
        $this->get($base.'/products/create')->assertForbidden();
        $this->assertSame(0, ShopProduct::query()->count());
    }

    public function test_audit_failure_rolls_back_product_create(): void
    {
        [$server, $category] = $this->catalog();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $audit = $this->mock(AuditLogger::class);
        $audit->shouldReceive('success')->once()->andReturn(null);
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/extensions/shop/servers/'.$server->id.'/products', $this->payload($category->id));
            $this->fail('Audit must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Shop product audit could not be recorded.', $exception->getMessage());
        }
        $this->assertSame(0, ShopProduct::query()->count());
    }
}
