<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AdminUiSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_card_button_tabs_and_filter_components_define_the_canonical_contract(): void
    {
        $card = File::get(resource_path('views/components/admin/card.blade.php'));
        $heading = File::get(resource_path('views/components/admin/card-heading.blade.php'));
        $button = File::get(resource_path('views/components/admin/button.blade.php'));
        $tabs = File::get(resource_path('views/components/admin/tabs.blade.php'));
        $tab = File::get(resource_path('views/components/admin/tab.blade.php'));
        $filter = File::get(resource_path('views/components/admin/filter-bar.blade.php'));
        $pagination = File::get(resource_path('views/components/admin/pagination.blade.php'));
        $css = File::get(public_path('assets/admin/css/components.css'));

        $this->assertStringContainsString("'admin-card'", $card);
        $this->assertStringContainsString("'form-card'", $card);
        $this->assertStringContainsString("'admin-card-heading'", $heading);
        $this->assertStringContainsString("'button-'.\$variant", $button);
        $this->assertStringContainsString("'admin-tabs'", $tabs);
        $this->assertStringContainsString("'admin-subtabs' => \$subtle", $tabs);
        $this->assertStringContainsString("'admin-tab'", $tab);
        $this->assertStringContainsString("'admin-filter-bar'", $filter);
        $this->assertStringContainsString("\$attributes->class('simple-pagination')", $pagination);
        $this->assertStringContainsString('$paginator->getUrlRange', $pagination);

        $this->assertStringContainsString(".admin-card,\n.form-card,", $css);
        $this->assertStringContainsString('.button:focus-visible {', $css);
        $this->assertStringContainsString('.admin-filter-bar > div:not(.admin-row-actions)', $css);
        $this->assertStringContainsString('.daily-reward-claim-filters {', $css);
    }

    public function test_reference_admin_pages_render_the_shared_ui_components(): void
    {
        $module = app(ModuleManager::class)->enable('support-tickets');
        app(ModuleRuntime::class)->bootModule($module);

        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('class="admin-tabs settings-section-tabs"', false)
            ->assertSee('class="admin-tab active settings-section-tab"', false);

        $this->actingAs($owner, 'admin')
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('class="admin-filter-bar users-filters"', false)
            ->assertSee('class="button button-primary"', false);

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/support-tickets/settings')
            ->assertOk()
            ->assertSee('class="admin-card form-card settings-narrow-card"', false)
            ->assertSee('class="admin-card-heading"', false)
            ->assertSee('class="admin-toggle-copy"', false)
            ->assertSee('data-editor-view-toggle', false);
    }

    public function test_core_admin_lists_use_the_shared_pagination_component(): void
    {
        foreach ([
            'admin/administrators/index.blade.php',
            'admin/audit/index.blade.php',
            'admin/news/index.blade.php',
            'admin/pages/index.blade.php',
            'admin/rewards/index.blade.php',
            'admin/settings/queue.blade.php',
            'admin/settings/security.blade.php',
            'admin/users/index.blade.php',
        ] as $view) {
            $contents = File::get(resource_path('views/'.$view));

            $this->assertStringContainsString('<x-admin.pagination', $contents, $view);
            $this->assertStringNotContainsString('<nav class="simple-pagination"', $contents, $view);
        }
    }

    public function test_legacy_audit_and_daily_reward_views_use_the_shared_wrappers(): void
    {
        $audit = File::get(resource_path('views/admin/audit/index.blade.php'));
        $dailyRewards = File::get(base_path('modules/daily-rewards/resources/views/admin/claims.blade.php'));
        $supportSettings = File::get(base_path('modules/support-tickets/resources/views/admin/settings.blade.php'));

        $this->assertStringContainsString('<x-admin.tabs', $audit);
        $this->assertStringContainsString('subtle class="audit-tabs"', $audit);
        $this->assertStringNotContainsString('<nav class="admin-subtabs audit-tabs"', $audit);

        $this->assertStringContainsString('<x-admin.filter-bar', $dailyRewards);
        $this->assertStringContainsString('daily-reward-claim-filters', $dailyRewards);
        $this->assertStringNotContainsString('<form class="users-filters"', $dailyRewards);

        $this->assertStringContainsString('<x-admin.toggle', $supportSettings);
        $this->assertStringNotContainsString('<label class="admin-toggle-row"', $supportSettings);
    }
}
