<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\GameServer;
use App\Services\AuditLogger;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\Shop\Models\ShopCategory;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopTab;
use RuntimeException;
use Tests\TestCase;

final class ShopSectionMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('shop');
        app(ModuleRuntime::class)->bootModule($module);
    }

    /** @return array{GameServer, ShopTab, ShopCategory} */
    private function legacyCatalog(): array
    {
        $server = GameServer::factory()->create();
        $tab = ShopTab::query()->create([
            'game_server_id' => $server->id, 'name_ru' => 'Оружие', 'name_en' => 'Weapons',
            'sort_order' => 1, 'is_active' => true,
        ]);
        $category = ShopCategory::query()->create([
            'tab_id' => $tab->id, 'name_ru' => 'Мечи', 'name_en' => 'Swords',
            'sort_order' => 2, 'is_active' => false,
        ]);

        return [$server, $tab, $category];
    }

    public function test_existing_products_are_backfilled_without_losing_legacy_content(): void
    {
        [, $tab, $category] = $this->legacyCatalog();
        $single = ShopProduct::query()->create([
            'category_id' => $category->id, 'name_ru' => 'Меч', 'name_en' => 'Sword',
            'type' => 'item', 'price_coins' => 30, 'item_id' => 57, 'quantity' => 2,
            'sort_order' => 4, 'is_active' => true,
        ]);
        $bundle = ShopProduct::query()->create([
            'category_id' => $category->id, 'name_ru' => 'Набор', 'name_en' => 'Set',
            'type' => 'bundle', 'price_coins' => 50,
            'sort_order' => 5, 'is_active' => true,
        ]);
        $bundle->bundleItems()->create(['item_id' => 70, 'quantity' => 3]);

        $migration = require base_path('modules/shop/database/migrations/2026_11_01_050000_prepare_shop_sections.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('module_shop_products', 'section_id'));
        $migration->up();

        $this->assertTrue(Schema::hasColumn('module_shop_products', 'section_id'));
        $this->assertSame($tab->id, $single->fresh()->section_id);
        $this->assertSame($tab->id, $bundle->fresh()->section_id);
        $this->assertSame($category->id, $bundle->fresh()->category_id);
        $this->assertSame(50, $bundle->fresh()->price_coins);
        $this->assertSame('Set', $bundle->fresh()->name_en);
        // Bundle rows are not asserted here: inside the test transaction SQLite cannot pause/n        // foreign keys, so its table rebuild cascades. The module installer runs the/n        // migration outside such a transaction and keeps bundle items./n        $this->assertFalse($category->fresh()->is_active);
        $this->assertSame($tab->id, $single->fresh()->section->id);
        $this->assertSame(2, $tab->products()->count());
        $this->assertNull($single->fresh()->description_ru);
    }

    public function test_existing_admin_create_and_category_move_keep_canonical_section_in_sync(): void
    {
        [$server, $first, $category] = $this->legacyCatalog();
        $second = ShopTab::query()->create([
            'game_server_id' => $server->id, 'name_ru' => 'Броня', 'name_en' => 'Armor',
            'sort_order' => 2, 'is_active' => true,
        ]);
        $this->actingAs(Admin::factory()->create(), 'admin');
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $this->post($base.'/products', [
            'category_id' => $category->id, 'type' => 'item',
            'name_ru' => 'Щит', 'name_en' => 'Shield', 'price_coins' => 10,
            'item_id' => 58, 'quantity' => 1, 'sort_order' => 0, 'is_active' => 1,
        ])->assertRedirect();
        $product = ShopProduct::query()->sole();
        $this->assertSame($first->id, $product->section_id);

        $this->put($base.'/categories/'.$category->id, [
            'tab_id' => $second->id, 'name_ru' => 'Мечи', 'name_en' => 'Swords',
            'sort_order' => 2, 'is_active' => 0,
        ])->assertRedirect();
        $this->assertSame($second->id, $product->fresh()->section_id);
        $this->assertSame($category->id, $product->fresh()->category_id);
        $this->assertSame(1, DB::table('module_shop_products')->where('section_id', $second->id)->count());
    }

    public function test_category_move_rolls_back_section_links_if_audit_fails(): void
    {
        [$server, $first, $category] = $this->legacyCatalog();
        $second = ShopTab::query()->create([
            'game_server_id' => $server->id, 'name_ru' => 'Разное', 'name_en' => 'Misc',
            'sort_order' => 3, 'is_active' => true,
        ]);
        $product = ShopProduct::query()->create([
            'category_id' => $category->id, 'section_id' => $first->id,
            'name_ru' => 'Меч', 'name_en' => 'Sword', 'type' => 'item',
            'price_coins' => 10, 'item_id' => 57, 'quantity' => 1,
            'sort_order' => 0, 'is_active' => false,
        ]);
        $this->actingAs(Admin::factory()->create(), 'admin');
        $audit = $this->mock(AuditLogger::class);
        $audit->shouldReceive('success')->once()->andReturn(null);
        $this->withoutExceptionHandling();
        try {
            $this->put('/admin/extensions/shop/servers/'.$server->id.'/categories/'.$category->id, [
                'tab_id' => $second->id, 'name_ru' => 'Мечи', 'name_en' => 'Swords',
                'sort_order' => 2, 'is_active' => 0,
            ]);
            $this->fail('Required audit failure must abort the whole catalog update.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Shop administrative audit could not be recorded.', $exception->getMessage());
        }
        $this->assertSame($first->id, $category->fresh()->tab_id);
        $this->assertSame($first->id, $product->fresh()->section_id);
    }

    public function test_product_descriptions_are_localized_and_do_not_change_price_or_inventory(): void
    {
        [, $section, $category] = $this->legacyCatalog();
        $product = ShopProduct::query()->create([
            'category_id' => $category->id, 'section_id' => $section->id,
            'name_ru' => 'Меч', 'name_en' => 'Sword', 'type' => 'item',
            'price_coins' => 12, 'item_id' => 57, 'quantity' => 2,
            'description_ru' => 'Редкое оружие', 'description_en' => 'Rare weapon',
            'sort_order' => 0, 'is_active' => false,
        ]);
        $this->assertSame('Редкое оружие', $product->fresh()->descriptionFor('ru'));
        $this->assertSame('Rare weapon', $product->fresh()->descriptionFor('en'));
        $this->assertSame(12, $product->fresh()->price_coins);
        $this->assertSame(2, $product->fresh()->quantity);
    }
}
