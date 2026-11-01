<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\GameServer;
use App\Services\AuditLogger;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\Shop\Models\ShopCategory;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopTab;
use RuntimeException;
use Tests\TestCase;

final class ShopUnifiedEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('shop');
        app(ModuleRuntime::class)->bootModule($module);
    }

    /** @return array{GameServer, ShopTab} */
    private function section(): array
    {
        $server = GameServer::factory()->create();
        $section = ShopTab::query()->create([
            'game_server_id' => $server->id, 'name_ru' => 'Оружие', 'name_en' => 'Weapons',
            'sort_order' => 0, 'is_active' => true,
        ]);

        return [$server, $section];
    }

    /** @return array<string, mixed> */
    private function payload(int $sectionId, array $items): array
    {
        return [
            '_shop_unified' => '1', '_shop_dialog' => 'new',
            'section_id' => $sectionId,
            'name_ru' => 'Набор', 'name_en' => 'Set',
            'description_ru' => 'Описание', 'description_en' => 'Description',
            'price_coins' => 100, 'sort_order' => 0, 'is_active' => 1,
            'items' => $items,
        ];
    }

    public function test_one_editor_creates_single_and_multi_item_products_and_converts_them(): void
    {
        [$server, $section] = $this->section();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $this->get($base)->assertOk()->assertSee('data-shop-open="shop-product-new"', false)
            ->assertDontSee(__('module-shop::messages.add_category'));

        $one = [['item_id' => 57, 'quantity' => 2]];
        $this->post($base.'/products', $this->payload($section->id, $one))->assertRedirect();
        $product = ShopProduct::query()->sole();
        $this->assertNull($product->category_id);
        $this->assertSame($section->id, $product->section_id);
        $this->assertSame('item', $product->type);
        $this->assertSame(57, $product->item_id);
        $this->assertSame('Описание', $product->description_ru);

        $multiple = [['item_id' => 57, 'quantity' => 2], ['item_id' => 88, 'quantity' => 3]];
        $this->put($base.'/products/'.$product->id, $this->payload($section->id, $multiple))
            ->assertRedirect();
        $this->assertSame('bundle', $product->fresh()->type);
        $this->assertNull($product->fresh()->item_id);
        $this->assertEqualsCanonicalizing([57, 88], $product->bundleItems()->pluck('item_id')->all());
        $this->assertSame(2, $product->bundleItems()->count());

        $this->put($base.'/products/'.$product->id, $this->payload($section->id, $one))
            ->assertRedirect();
        $this->assertSame('item', $product->fresh()->type);
        $this->assertSame(0, $product->bundleItems()->count());
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'like', 'shop.product_%')->count());
    }

    public function test_validates_duplicate_ids_count_price_and_foreign_server(): void
    {
        [$server, $section] = $this->section();
        [$other, $foreign] = $this->section();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $this->post($base.'/products', $this->payload($foreign->id, [['item_id' => 57, 'quantity' => 1]]))
            ->assertSessionHasErrors('section_id');
        $this->post($base.'/products', $this->payload($section->id, [['item_id' => 57, 'quantity' => 1], ['item_id' => 57, 'quantity' => 2]]))
            ->assertSessionHasErrors('items');
        $this->post($base.'/products', array_replace($this->payload($section->id, [['item_id' => 57, 'quantity' => 1]]), ['price_coins' => 1.5]))
            ->assertSessionHasErrors('price_coins');
        $rows = array_fill(0, 101, ['item_id' => 57, 'quantity' => 1]);
        $this->post($base.'/products', $this->payload($section->id, $rows))->assertSessionHasErrors('items');
        $this->assertSame(0, ShopProduct::query()->count());
        $this->assertNotSame($server->id, $other->id);
    }

    public function test_audit_failure_rolls_back_product_and_items(): void
    {
        [$server, $section] = $this->section();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $audit = $this->mock(AuditLogger::class);
        $audit->shouldReceive('success')->once()->andReturn(null);
        $this->withoutExceptionHandling();
        try {
            $this->post('/admin/extensions/shop/servers/'.$server->id.'/products', $this->payload($section->id, [
                ['item_id' => 57, 'quantity' => 1], ['item_id' => 88, 'quantity' => 1],
            ]));
            $this->fail('Failed audit must roll back product and bundle items.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Shop product audit could not be recorded.', $exception->getMessage());
        }
        $this->assertSame(0, ShopProduct::query()->count());
        $this->assertDatabaseCount('module_shop_bundle_items', 0);
    }

    public function test_hidden_legacy_category_remains_hidden_and_new_products_ignore_legacy_category(): void
    {
        [$server, $section] = $this->section();
        $category = ShopCategory::query()->create([
            'tab_id' => $section->id, 'name_ru' => 'Старое', 'name_en' => 'Old',
            'sort_order' => 0, 'is_active' => false,
        ]);
        $legacy = ShopProduct::query()->create([
            'category_id' => $category->id, 'type' => 'item', 'name_ru' => 'Скрыт',
            'name_en' => 'Hidden', 'item_id' => 10, 'quantity' => 1,
            'price_coins' => 30, 'sort_order' => 0, 'is_active' => true,
        ]);
        $this->actingAs(Admin::factory()->create(), 'admin');
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $this->post($base.'/products', $this->payload($section->id, [['item_id' => 11, 'quantity' => 1]]))
            ->assertRedirect();
        $this->assertSame($section->id, $legacy->fresh()->section_id);
        $this->assertFalse($category->fresh()->is_active);
        $this->assertSame(1, ShopProduct::query()->whereNull('category_id')->count());
    }
}
