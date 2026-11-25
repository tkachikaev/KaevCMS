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
            ->assertSee('assets/admin/js/navigation.js', false);
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
            ->assertSee('data-admin-menu-group="appearance"', false)
            ->assertSee('/admin/modules', false)
            ->assertDontSee(__('Coming soon'))
            ->assertSee('data-admin-settings-link', false)
            ->assertDontSee('data-admin-menu-group="system"', false)
            ->assertSee('admin-menu-group-summary', false)
            ->assertSee('assets/admin/js/navigation.js', false)
            ->assertSee('settings-section-tabs', false)
            ->assertSee('Settings sections')
            ->assertSee('/admin/settings/admin-panel', false)
            ->assertSee('/admin/settings/registration', false)
            ->assertSee('/admin/settings/game-accounts', false)
            ->assertSee('/admin/settings/security', false);

        $document = new DOMDocument;
        $this->assertTrue($document->loadHTML(
            $response->getContent(),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        ));

        $xpath = new DOMXPath($document);
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

    public function test_admin_appearance_controls_are_not_exposed_on_the_public_site(): void
    {
        $admin = Admin::factory()->create();

        $this->actingAs($admin, 'admin')->get('/admin')->assertOk()
            ->assertSee('data-admin-appearance-toggle', false);
        $this->get('/')->assertOk()
            ->assertDontSee('data-admin-appearance-toggle', false);
    }

    public function test_admin_copy_actions_use_the_shared_clipboard_helper(): void
    {
        $layout = file_get_contents(resource_path('views/admin/layouts/app.blade.php'));
        $clipboard = file_get_contents(public_path('assets/admin/js/clipboard.js'));

        $this->assertIsString($layout);
        $this->assertIsString($clipboard);
        $this->assertStringContainsString('assets/admin/js/clipboard.js', $layout);
        $this->assertStringContainsString('KaevCMSAdminClipboard', $clipboard);

        foreach (['system.js', 'system-updates.js', 'two-factor.js'] as $script) {
            $contents = file_get_contents(public_path('assets/admin/js/'.$script));

            $this->assertIsString($contents);
            $this->assertStringContainsString('KaevCMSAdminClipboard', $contents, $script);
            $this->assertStringNotContainsString('navigator.clipboard', $contents, $script);
            $this->assertStringNotContainsString("document.execCommand('copy')", $contents, $script);
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
}
