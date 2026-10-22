<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ModuleCatalogLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_module_cards_open_the_module_section(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner, 'locale' => 'ru']);

        // Disabled modules have no section to open.
        $this->actingAs($owner, 'admin')
            ->get('/admin/modules')
            ->assertOk()
            ->assertDontSee('data-testid="module-open"', false);

        $module = app(ModuleManager::class)->enable('promo-codes');
        app(ModuleRuntime::class)->bootModule($module);

        $this->actingAs($owner, 'admin')
            ->get('/admin/modules')
            ->assertOk()
            ->assertSee('module-actions module-actions-pair', false)
            ->assertSee('href="'.route('admin.module-pages.promo-codes.index').'" data-testid="module-open">Открыть</a>', false)
            ->assertDontSee('/admin/extensions/donations"', false);
    }
}
