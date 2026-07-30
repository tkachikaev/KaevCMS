<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_root_is_the_main_panel_entry_point(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Main Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Панель управления')
            ->assertSee('Темы')
            ->assertSee('Новости')
            ->assertSee('Страницы')
            ->assertSee('Журнал действий')
            ->assertSee('class="admin-account-avatar" aria-hidden="true"><span>M</span>', false)
            ->assertSee('assets/admin/css/base.css')
            ->assertSee('assets/admin/css/catalogs.css');

        $adminCss = $this->adminStyles();
        $this->assertIsString($adminCss);
        $this->assertStringContainsString(
            '.admin-account-avatar > span { display: grid; place-items: center; width: 100%; height: 100%; line-height: 1; transform: translateY(1px); }',
            $adminCss,
        );
    }

    public function test_administrative_visual_system_uses_shared_light_theme_tokens_and_tabs(): void
    {
        $css = $this->adminStyles();

        $this->assertStringContainsString('--admin-page-bg:', $css);
        $this->assertStringContainsString('--admin-surface-muted:', $css);
        $this->assertStringContainsString('--admin-primary:', $css);
        $this->assertStringContainsString('--admin-shadow-card:', $css);
        $this->assertStringContainsString('.admin-tabs {', $css);
        $this->assertStringContainsString('.admin-tab.active {', $css);
        $this->assertStringContainsString('.server-drawer-tab.active {', $css);

        $admin = Admin::query()->create([
            'name' => 'Visual Admin',
            'email' => 'visual-admin@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('class="admin-tabs settings-section-tabs"', false)
            ->assertSee('class="admin-tab settings-section-tab active"', false);

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/mail')
            ->assertOk()
            ->assertSee('class="admin-tabs mail-template-tabs"', false)
            ->assertSee('class="admin-tab mail-template-tab active"', false);
    }

    public function test_admin_catalogues_use_shared_enterprise_components(): void
    {
        $css = $this->adminStyles();

        foreach ([
            '.admin-overview {',
            '.admin-card-row,',
            '.admin-filter-bar {',
            '.admin-subtabs {',
            '.admin-table-wrap {',
            '.admin-actions-panel {',
        ] as $component) {
            $this->assertStringContainsString($component, $css);
        }

        $this->assertStringContainsString('--admin-success-soft:', $css);
        $this->assertStringContainsString('--admin-warning-soft:', $css);
        $this->assertStringContainsString('--admin-danger-soft:', $css);

        $this->assertStringContainsString(
            'class="admin-card-row content-row"',
            File::get(resource_path('views/admin/news/index.blade.php')),
        );
        $this->assertStringContainsString(
            'class="admin-card-list-header user-row user-row-header"',
            File::get(resource_path('views/admin/users/index.blade.php')),
        );
        $this->assertStringContainsString(
            'class="admin-table audit-table"',
            File::get(resource_path('views/admin/audit/index.blade.php')),
        );
        foreach ([
            'views/admin/themes/index.blade.php',
            'views/admin/account-themes/index.blade.php',
        ] as $themeCatalogue) {
            $this->assertStringContainsString(
                "['admin-card-row', 'admin-catalog-row', 'theme-card'",
                File::get(resource_path($themeCatalogue)),
            );
        }

        $this->assertStringContainsString(
            'class="admin-catalog-list module-list"',
            File::get(resource_path('views/admin/modules/index.blade.php')),
        );
        $this->assertStringContainsString(
            ".admin-catalog-row {\n    display: grid;",
            $css,
        );

        $admin = Admin::query()->create([
            'name' => 'Component Admin',
            'email' => 'components@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/news')
            ->assertOk()
            ->assertSee('class="admin-overview content-toolbar"', false);

        $this->actingAs($admin, 'admin')
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('class="admin-overview users-summary"', false)
            ->assertSee('class="admin-filter-bar users-filters"', false);

        $this->actingAs($admin, 'admin')
            ->get('/admin/logs')
            ->assertOk()
            ->assertSee('class="admin-overview audit-summary"', false)
            ->assertSee('class="admin-subtabs audit-tabs"', false);
    }

    public function test_settings_actions_and_help_text_use_separate_rows(): void
    {
        $css = $this->adminStyles();

        $this->assertStringContainsString(
            ".admin-path-settings-controls,\n.system-monitor-settings-controls {\n    display: grid;",
            $css,
        );
        $this->assertStringContainsString(
            ".admin-path-settings-controls > .button,\n.system-monitor-settings-controls > .button {",
            $css,
        );
        $this->assertStringContainsString(".settings-field {\n    display: grid;", $css);
        $this->assertStringContainsString(
            '.settings-grid.two-columns {',
            $css,
        );

        $admin = Admin::query()->create([
            'name' => 'Settings Layout Admin',
            'email' => 'settings-layout@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/game-accounts')
            ->assertOk()
            ->assertSee('data-game-account-limit-help', false)
            ->assertSee('Лимит считается суммарно по всем настроенным LoginServer.')
            ->assertSee('Временно недоступные игровые аккаунты также учитываются в лимите.');
    }

    public function test_sidebar_has_one_settings_entry_and_settings_pages_use_local_tabs(): void
    {
        $admin = Admin::query()->create([
            'name' => 'English Admin',
            'email' => 'english-admin@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
            'locale' => 'en',
        ]);

        $response = $this->actingAs($admin, 'admin')->get('/admin/settings');

        $response
            ->assertOk()
            ->assertSeeInOrder([
                'Content',
                'Themes',
                'Servers',
                'Game servers',
                'Login servers',
                'Users',
                'Administrators',
                'Mail',
                'Settings',
                'Audit log',
                'Modules',
            ])
            ->assertSee('data-admin-menu-group="appearance"', false)
            ->assertSee('/admin/modules', false)
            ->assertDontSee(__('Coming soon'))
            ->assertSee('data-admin-settings-link', false)
            ->assertDontSee('data-admin-menu-group="system"', false)
            ->assertSee('admin-menu-group-summary', false)
            ->assertSee('assets/admin/js/navigation.js', false)
            ->assertSee('settings-section-tabs', false)
            ->assertSee('Settings sections')
            ->assertSeeInOrder([
                'Site',
                'Administrator panel',
                'Registration',
                'Game accounts',
                'Languages',
                'Security',
                'System information',
            ]);

        $document = new DOMDocument;
        $this->assertTrue($document->loadHTML(
            $response->getContent(),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        ));

        $xpath = new DOMXPath($document);
        $this->assertSame(
            'Themes',
            trim((string) $xpath->evaluate('string(//details[@data-admin-menu-group="appearance"]/summary/span[1])')),
        );
        $this->assertSame(
            'Site',
            trim((string) $xpath->evaluate('string(//details[@data-admin-menu-group="appearance"]//a[1]/span)')),
        );
        $this->assertSame(
            'Account',
            trim((string) $xpath->evaluate('string(//details[@data-admin-menu-group="appearance"]//a[2]/span)')),
        );
        $this->assertSame(
            1.0,
            $xpath->evaluate('count(//a[@data-admin-settings-link])'),
        );
        $this->assertSame(
            1.0,
            $xpath->evaluate('count(//a[@data-admin-settings-link and @data-current and contains(@href, "/admin/settings")])'),
        );
    }

    public function test_nested_settings_pages_keep_the_single_settings_entry(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Nested Settings Admin',
            'email' => 'nested-settings@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'admin')->get('/admin/settings/game-accounts');

        $response
            ->assertOk()
            ->assertSee('data-admin-settings-link', false)
            ->assertDontSee('data-admin-menu-group="system"', false);

        $document = new DOMDocument;
        $this->assertTrue($document->loadHTML(
            $response->getContent(),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        ));

        $xpath = new DOMXPath($document);
        $this->assertSame(
            1.0,
            $xpath->evaluate('count(//a[@data-admin-settings-link and @data-current and contains(@href, "/admin/settings")])'),
        );
    }

    public function test_settings_entry_is_not_current_for_separate_mail_settings(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Mail Settings Admin',
            'email' => 'mail-settings@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'admin')->get('/admin/settings/mail');

        $response->assertOk();

        $document = new DOMDocument;
        $this->assertTrue($document->loadHTML(
            $response->getContent(),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        ));

        $xpath = new DOMXPath($document);
        $this->assertSame(
            0.0,
            $xpath->evaluate('count(//a[@data-admin-settings-link and @data-current])'),
        );
    }

    public function test_admin_navigation_uses_livewire_without_full_page_reload(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Navigation Admin',
            'email' => 'navigation-admin@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'admin')->get('/admin');

        $response
            ->assertOk()
            ->assertSee('wire:navigate', false)
            ->assertSee('data-navigate-track', false)
            ->assertSee('data-update-uri=', false)
            ->assertSee('data-navigate-once="true"', false);

        $this->assertGreaterThanOrEqual(12, substr_count($response->getContent(), 'wire:navigate'));
    }

    public function test_admin_sidebar_is_persisted_across_livewire_navigation(): void
    {
        $layout = file_get_contents(resource_path('views/admin/layouts/app.blade.php'));
        $panel = file_get_contents(resource_path('views/admin/layouts/panel.blade.php'));
        $navigation = file_get_contents(resource_path('views/admin/partials/navigation.blade.php'));

        $this->assertIsString($layout);
        $this->assertIsString($panel);
        $this->assertIsString($navigation);
        $this->assertStringContainsString('assets/admin/js/page-lifecycle.js', $layout);
        $this->assertStringContainsString('assets/admin/js/navigation.js', $layout);
        $this->assertStringContainsString('assets/admin/js/read-only.js', $layout);
        $this->assertStringContainsString("'base',", $layout);
        $this->assertStringContainsString("'catalogs',", $layout);
        $this->assertStringNotContainsString('assets/admin/css/app.css', $layout);
        $this->assertStringContainsString('defer data-navigate-track data-navigate-once', $layout);
        $this->assertStringContainsString("@persist('admin-sidebar')", $panel);
        $this->assertStringContainsString('wire:navigate:scroll', $panel);
        $this->assertStringContainsString('data-admin-sidebar', $panel);
        $this->assertStringContainsString('data-admin-read-only-role', $panel);
        $this->assertStringContainsString('wire:navigate.hover', $navigation);
        $this->assertStringContainsString('wire:current.exact="active"', $navigation);
        $this->assertStringContainsString('data-admin-settings-link', $navigation);
        $this->assertStringContainsString('data-current', $navigation);
        $this->assertStringContainsString('data-admin-menu-group="modules"', $navigation);
        $this->assertStringNotContainsString("route('admin.settings.game-server-features.index')", $navigation);
        $modulesLinkPosition = strpos($navigation, "route('admin.modules.index')");
        $moduleLinksPosition = strpos($navigation, '@foreach($moduleAdminLinks as $moduleLink)');
        if (is_int($modulesLinkPosition) === false || is_int($moduleLinksPosition) === false) {
            $this->fail('The module navigation group is incomplete.');
        }
        $this->assertLessThan($moduleLinksPosition, $modulesLinkPosition);
        $this->assertStringNotContainsString("'active' => request()->routeIs", $navigation);
    }

    public function test_admin_navigation_stabilizes_the_shell_during_page_swaps(): void
    {
        $navigation = file_get_contents(public_path('assets/admin/js/navigation.js'));
        $styles = $this->adminStyles();

        $this->assertIsString($navigation);
        $this->assertIsString($styles);
        $this->assertStringContainsString('livewire:navigate', $navigation);
        $this->assertStringContainsString('livewire:navigated', $navigation);
        $this->assertStringContainsString('synchronizeSettingsLink(sidebar)', $navigation);
        $this->assertStringContainsString("['mail', 'game-server', 'login-server']", $navigation);
        $this->assertStringContainsString('admin-is-navigating', $navigation);
        $this->assertStringContainsString('scrollbar-gutter: stable', $styles);
        $this->assertStringContainsString('html.admin-is-navigating .admin-main', $styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        $this->assertStringContainsString('a.admin-menu-item.active:hover', $styles);
        $this->assertStringContainsString('a.admin-menu-item[data-current]:hover', $styles);
    }

    public function test_settings_fields_use_the_shared_thin_control_border(): void
    {
        $components = file_get_contents(public_path('assets/admin/css/components.css'));

        $this->assertIsString($components);
        $this->assertStringContainsString('.settings-field input,', $components);
        $this->assertStringContainsString('border: 1px solid var(--admin-border-strong);', $components);
    }

    public function test_admin_scripts_use_one_livewire_page_lifecycle(): void
    {
        $lifecycle = file_get_contents(public_path('assets/admin/js/page-lifecycle.js'));
        $layout = file_get_contents(resource_path('views/admin/layouts/app.blade.php'));

        $this->assertIsString($lifecycle);
        $this->assertIsString($layout);
        $this->assertStringContainsString('registerPage(name, initialize)', $lifecycle);
        $this->assertStringContainsString('livewire:navigating', $lifecycle);
        $this->assertStringContainsString('livewire:navigated', $lifecycle);
        $this->assertStringContainsString('cleanupAll()', $lifecycle);
        $this->assertStringContainsString('page-lifecycle.js', $layout);

        $scripts = [
            'admin-panel.js',
            'custom-mail.js',
            'localization.js',
            'mail-delivery.js',
            'mail-templates.js',
            'news-actions.js',
            'news-editor.js',
            'page-actions.js',
            'read-only.js',
            'security.js',
            'server-monitor.js',
            'settings.js',
            'system.js',
            'two-factor.js',
            'users.js',
        ];

        foreach ($scripts as $script) {
            $contents = file_get_contents(public_path('assets/admin/js/'.$script));
            $this->assertIsString($contents);
            $this->assertStringContainsString('window.KaevCMSAdmin.registerPage', $contents, $script);
        }

        foreach (File::allFiles(resource_path('views/admin')) as $view) {
            $contents = $view->getContents();
            if (str_contains($contents, 'assets/admin/js/') === false) {
                continue;
            }

            foreach (preg_split('/\R/', $contents) ?: [] as $line) {
                if (str_contains($line, 'assets/admin/js/') && str_contains($line, '<script src=')) {
                    $this->assertStringContainsString('data-navigate-once', $line, $view->getPathname());
                }
            }
        }
    }

    public function test_old_dashboard_address_is_not_registered(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Main Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/dashboard')
            ->assertNotFound();
    }

    private function adminStyles(): string
    {
        return collect([
            'base',
            'layout',
            'content',
            'infrastructure',
            'components',
            'extensions',
            'catalogs',
        ])->map(
            static fn (string $name): string => File::get(public_path('assets/admin/css/'.$name.'.css')),
        )->implode("\n");
    }
}
