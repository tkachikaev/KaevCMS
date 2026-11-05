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

    /**
     * @param  list<array{item_id:int, quantity:int}>  $items
     * @param  array<string, array<string, mixed>>  $translations
     * @return array<string, mixed>
     */
    private function payload(int $sectionId, array $items, ?array $translations = null): array
    {
        return [
            '_shop_unified' => '1', '_shop_dialog' => 'new',
            'section_id' => $sectionId,
            'translations' => $translations ?? [
                'ru' => ['name' => 'Набор', 'description' => 'Описание', 'name_manual' => 1],
                'en' => ['name' => 'Set', 'description' => 'Description', 'name_manual' => 1],
            ],
            'price_coins' => 100, 'is_active' => 1,
            'items' => $items,
        ];
    }

    public function test_one_editor_creates_single_and_multi_item_products_and_converts_them(): void
    {
        [$server, $section] = $this->section();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $this->get($base)->assertOk()->assertSee('data-shop-open="shop-product-new"', false)
            ->assertSee('data-shop-item-search-input', false)
            ->assertDontSee(__('module-shop::messages.add_category'));

        $one = [['item_id' => 57, 'quantity' => 2]];
        $this->post($base.'/products', $this->payload($section->id, $one))->assertRedirect();
        $product = ShopProduct::query()->sole();
        $this->assertNull($product->category_id);
        $this->assertSame($section->id, $product->section_id);
        $this->assertSame('item', $product->type);
        $this->assertSame(57, $product->item_id);
        $this->assertSame('Описание', $product->description_ru);
        $this->assertSame('Description', $product->fresh('translations')->descriptionFor('en'));

        $multiple = [['item_id' => 57, 'quantity' => 2], ['item_id' => 88, 'quantity' => 3]];
        $this->put($base.'/products/'.$product->id, $this->payload($section->id, $multiple))
            ->assertRedirect();
        $this->assertSame('bundle', $product->fresh()->type);
        $this->assertNull($product->fresh()->item_id);
        $this->assertEqualsCanonicalizing([57, 88], $product->bundleItems()->pluck('item_id')->all());

        $this->put($base.'/products/'.$product->id, $this->payload($section->id, $one))
            ->assertRedirect();
        $this->assertSame('item', $product->fresh()->type);
        $this->assertSame(0, $product->bundleItems()->count());
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'like', 'shop.product_%')->count());
    }

    public function test_single_item_is_named_after_the_item_until_the_admin_types_a_name(): void
    {
        [$server, $section] = $this->section();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $auto = ['ru' => ['name' => '', 'name_manual' => 0], 'en' => ['name' => 'stale', 'name_manual' => 0]];
        $this->post($base.'/products', $this->payload($section->id, [['item_id' => 57, 'quantity' => 1000]], $auto))
            ->assertRedirect();
        $product = ShopProduct::query()->with('translations')->sole();
        $this->assertSame('Адена', $product->nameFor('ru'));
        $this->assertSame('Adena', $product->nameFor('en'));
        $this->assertFalse((bool) $product->translations->firstWhere('locale', 'ru')->name_manual);

        $manual = ['ru' => ['name' => 'Мешок адены', 'name_manual' => 1], 'en' => ['name' => '', 'name_manual' => 0]];
        $this->put($base.'/products/'.$product->id, $this->payload($section->id, [['item_id' => 57, 'quantity' => 1000]], $manual))
            ->assertRedirect();
        $product = $product->fresh('translations');
        $this->assertSame('Мешок адены', $product->nameFor('ru'));
        $this->assertSame('Мешок адены', $product->name_ru);
        $this->assertSame('Adena', $product->nameFor('en'));
    }

    public function test_bundle_needs_a_typed_name(): void
    {
        [$server, $section] = $this->section();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $empty = ['ru' => ['name' => '', 'name_manual' => 0], 'en' => ['name' => '', 'name_manual' => 0]];
        $this->post('/admin/extensions/shop/servers/'.$server->id.'/products', $this->payload($section->id, [
            ['item_id' => 57, 'quantity' => 1], ['item_id' => 88, 'quantity' => 1],
        ], $empty))->assertSessionHasErrors('translations.ru.name');
        $this->assertSame(0, ShopProduct::query()->count());
    }

    public function test_admin_cards_show_artwork_contents_and_visibility(): void
    {
        [$server, $section] = $this->section();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $this->post($base.'/products', $this->payload($section->id, [
            ['item_id' => 57, 'quantity' => 1], ['item_id' => 4037, 'quantity' => 2],
        ]))->assertRedirect();
        $this->get($base)->assertOk()
            ->assertDontSee('data-shop-view-toggle', false)
            ->assertSee('data-shop-art-count="2"', false)
            ->assertSee('shop-product-artwork', false)
            ->assertSee(trans_choice('module-shop::messages.bundle_meta', 2, ['count' => format_number(2)]))
            ->assertSee('data-shop-sortable', false);
    }

    public function test_disabled_section_marks_products_hidden(): void
    {
        [$server, $section] = $this->section();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $this->post($base.'/products', $this->payload($section->id, [['item_id' => 57, 'quantity' => 1]]))->assertRedirect();

        // A product may be individually active, but an inactive section must
        // never be labelled visible in the administration cards.
        $section->update(['is_active' => false]);
        $disabled = $this->get($base)->assertOk()->assertSee(__('module-shop::messages.hidden'));
        $this->assertSame(1, substr_count($disabled->getContent(), 'data-shop-product-visible="0"'));
        $section->update(['is_active' => true]);
        $enabled = $this->get($base)->assertOk();
        $this->assertSame(1, substr_count($enabled->getContent(), 'data-shop-product-visible="1"'));
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
        $this->assertDatabaseCount('module_shop_product_translations', 0);
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
        $this->assertSame(1, (int) ShopProduct::query()->whereNull('category_id')->value('sort_order'));
    }

    public function test_sections_are_created_renamed_hidden_and_deleted_in_place(): void
    {
        [$server] = $this->section();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $base = '/admin/extensions/shop/servers/'.$server->id;

        $this->post($base.'/sections', ['name' => '  '])->assertSessionHasErrors('name');
        $this->post($base.'/sections', ['name' => 'Расходники'])->assertRedirect();
        $section = ShopTab::query()->where('name_ru', 'Расходники')->sole();
        $this->assertSame(1, $section->sort_order);
        $this->assertTrue($section->is_active);

        $this->put($base.'/sections/'.$section->id, ['names' => ['ru' => 'Зелья', 'en' => 'Potions'], 'is_active' => 1])
            ->assertRedirect();
        $section = $section->fresh('translations');
        $this->assertSame('Зелья', $section->nameFor('ru'));
        $this->assertSame('Potions', $section->nameFor('en'));
        $this->assertSame('Potions', $section->name_en);

        $this->patch($base.'/sections/'.$section->id.'/visibility', ['is_active' => 0])->assertRedirect();
        $this->assertFalse($section->fresh()->is_active);

        $this->delete($base.'/tabs/'.$section->id, ['confirm' => 1])->assertRedirect();
        $this->assertNull($section->fresh());
        $this->assertDatabaseMissing('module_shop_section_translations', ['section_id' => $section->id]);
    }

    public function test_order_updates_require_the_complete_list_of_this_server(): void
    {
        [$server, $first] = $this->section();
        $second = ShopTab::query()->create(['game_server_id' => $server->id, 'name_ru' => 'Б', 'name_en' => 'B', 'sort_order' => 1, 'is_active' => true]);
        [, $foreign] = $this->section();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $base = '/admin/extensions/shop/servers/'.$server->id;

        $this->putJson($base.'/sections/order', ['ids' => [$second->id, $first->id]])->assertOk()->assertJson(['saved' => true]);
        $this->assertSame(0, $second->fresh()->sort_order);
        $this->assertSame(1, $first->fresh()->sort_order);
        $this->putJson($base.'/sections/order', ['ids' => [$second->id]])->assertUnprocessable();
        $this->putJson($base.'/sections/order', ['ids' => [$second->id, $first->id, $foreign->id]])->assertUnprocessable();

        $this->post($base.'/products', $this->payload($first->id, [['item_id' => 57, 'quantity' => 1]]))->assertRedirect();
        $this->post($base.'/products', $this->payload($first->id, [['item_id' => 88, 'quantity' => 1]]))->assertRedirect();
        $ids = ShopProduct::query()->orderBy('id')->pluck('id')->all();
        $this->putJson($base.'/sections/'.$first->id.'/products/order', ['ids' => array_reverse($ids)])->assertOk();
        $this->assertSame([$ids[1], $ids[0]], ShopProduct::query()->orderBy('sort_order')->pluck('id')->all());
        $this->putJson('/admin/extensions/shop/servers/'.$foreign->game_server_id.'/sections/'.$first->id.'/products/order', ['ids' => $ids])
            ->assertNotFound();
    }

    public function test_item_lookup_returns_names_and_icons_for_admins_only(): void
    {
        [$server] = $this->section();
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $this->actingAs(Admin::factory()->auditor()->create(), 'admin');
        $this->getJson($base.'/items/57')->assertOk()
            ->assertJsonPath('id', 57)->assertJsonPath('known', true)
            ->assertJsonPath('names.ru', 'Адена')->assertJsonPath('names.en', 'Adena');
        $this->getJson($base.'/items?q=57')->assertOk()->assertJsonPath('items.0.id', 57);
        $this->getJson($base.'/items?q=аден')->assertOk()->assertJsonPath('items.0.id', 57);
        $this->getJson($base.'/items')->assertUnprocessable();

        $this->actingAs(Admin::factory()->editor()->create(), 'admin');
        $this->getJson($base.'/items/57')->assertForbidden();
    }

    public function test_auditor_cannot_change_sections_or_order(): void
    {
        [$server, $section] = $this->section();
        $base = '/admin/extensions/shop/servers/'.$server->id;
        $this->actingAs(Admin::factory()->auditor()->create(), 'admin');
        $this->get($base)->assertOk()->assertDontSee('data-shop-sortable', false);
        $this->post($base.'/sections', ['name' => 'X'])->assertForbidden();
        $this->patch($base.'/sections/'.$section->id.'/visibility', ['is_active' => 0])->assertForbidden();
        $this->putJson($base.'/sections/order', ['ids' => [$section->id]])->assertForbidden();
        $this->assertSame(1, ShopTab::query()->count());
    }

    public function test_overview_opens_a_server_and_the_shop_switch_returns_to_it(): void
    {
        [$server] = $this->section();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $this->get('/admin/extensions/shop')->assertRedirectContains('/admin/extensions/shop/servers/');
        $this->put('/admin/extensions/shop/settings', ['sales_enabled' => 1, '_return_server' => $server->id])
            ->assertRedirect('/admin/extensions/shop/servers/'.$server->id);
        $this->assertDatabaseHas('module_shop_settings', ['setting_key' => 'global', 'sales_enabled' => true]);
        $this->get('/admin/extensions/shop/servers/'.$server->id)->assertOk()
            ->assertSee(__('module-shop::messages.global_sales_on'))
            ->assertSee(__('module-shop::messages.sales_need_both'));
    }
}
