<?php

namespace Tests\Feature;

use App\Models\NavigationLink;
use App\Models\User;
use App\Services\CmsSettings;
use App\Services\RegistrationSettings;
use App\Support\Themes\AccountThemeManager;
use App\Support\Themes\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BundledAureliaThemesTest extends TestCase
{
    use RefreshDatabase;

    public function test_fallback_account_theme_uses_the_shared_core_navigation_runtime(): void
    {
        $fallbackTheme = app(AccountThemeManager::class)->inspect('luxury');

        $this->assertTrue($fallbackTheme['valid'], implode(PHP_EOL, $fallbackTheme['errors']));
        $this->assertTrue($fallbackTheme['compatible'], implode(PHP_EOL, $fallbackTheme['errors']));
        $this->assertFileExists(public_path('assets/account/css/components.css'));
        $this->assertFileExists(public_path('assets/account/js/navigation.js'));
        $this->assertFileDoesNotExist(public_path('account-themes/luxury/assets/js/navigation.js'));
    }

    public function test_bundled_aurelia_manifests_and_assets_are_valid(): void
    {
        $publicTheme = app(ThemeManager::class)->inspect('kaev-aurelia');
        $accountTheme = app(AccountThemeManager::class)->inspect('kaev-aurelia');

        $this->assertTrue($publicTheme['valid'], implode(PHP_EOL, $publicTheme['errors']));
        $this->assertTrue($publicTheme['compatible'], implode(PHP_EOL, $publicTheme['errors']));
        $this->assertSame('Kaev Aurelia', $publicTheme['name']);
        $this->assertSame('1.0.14', $publicTheme['version']);
        $this->assertNotNull($publicTheme['preview_url']);

        $this->assertTrue($accountTheme['valid'], implode(PHP_EOL, $accountTheme['errors']));
        $this->assertTrue($accountTheme['compatible'], implode(PHP_EOL, $accountTheme['errors']));
        $this->assertSame('Kaev Aurelia Account', $accountTheme['name']);
        $this->assertSame('1.6.10', $accountTheme['version']);
        $this->assertNotNull($accountTheme['preview_url']);

        foreach ($this->publicThemeFiles() as $file) {
            $this->assertFileExists(base_path('themes/kaev-aurelia/views/'.$file));
        }

        foreach ($this->accountThemeFiles() as $file) {
            $this->assertFileExists(base_path('account-themes/kaev-aurelia/views/'.$file));
        }

        $this->assertFileExists(public_path('themes/kaev-aurelia/assets/css/app.css'));
        $this->assertFileExists(public_path('themes/kaev-aurelia/assets/js/app.js'));
        $this->assertFileExists(public_path('themes/kaev-aurelia/assets/images/hero.webp'));
        $this->assertFileExists(public_path('account-themes/kaev-aurelia/assets/css/app.css'));
        $this->assertFileExists(public_path('assets/account/js/navigation.js'));
        $this->assertFileDoesNotExist(public_path('account-themes/kaev-aurelia/assets/js/navigation.js'));
        $this->assertFileDoesNotExist(public_path('account-themes/luxury/assets/js/navigation.js'));
        $this->assertFileExists(public_path('account-themes/kaev-aurelia/assets/images/hero.webp'));

        $accountCss = $this->readFile(public_path('account-themes/kaev-aurelia/assets/css/app.css'));
        $sharedAccountCss = $this->readFile(public_path('assets/account/css/components.css'));
        $this->assertStringContainsString(
            '.account-character-avatar { width: 48px; height: 48px; position: relative; display: grid; place-items: center; flex-shrink: 0; overflow: hidden;',
            $accountCss,
        );
        $this->assertStringContainsString(
            '.account-character-avatar > span { display: block; line-height: 1; transform: translateY(1px); }',
            $accountCss,
        );
        $this->assertStringContainsString('.account-surface {', $accountCss);
        $this->assertStringContainsString('border-radius: var(--account-radius-lg);', $accountCss);
        $this->assertStringContainsString('.account-form-aside {', $accountCss);
        $this->assertStringContainsString('position: relative;', $accountCss);
        $this->assertStringContainsString('--account-shared-avatar-preview-background:', $accountCss);
        $this->assertStringContainsString('.reward-history-card {', $sharedAccountCss);
        $this->assertStringContainsString('.account-avatar-grid {', $sharedAccountCss);
        $this->assertStringContainsString('.account-avatar-modal {', $sharedAccountCss);
        $this->assertStringContainsString('.account-operation-modal{', $sharedAccountCss);
        $this->assertStringContainsString('.account-tool-grid {', $sharedAccountCss);
        $this->assertStringContainsString('.account-detail-hero { padding: 28px 30px; }', $sharedAccountCss);
    }

    public function test_public_aurelia_theme_can_be_activated_and_renders_its_own_shell(): void
    {
        app(ThemeManager::class)->activate('kaev-aurelia');

        $this->get('/')
            ->assertOk()
            ->assertSee('class="kaev-aurelia"', false)
            ->assertSee('themes/kaev-aurelia/assets/css/app.css', false)
            ->assertSee('themes/kaev-aurelia/assets/js/app.js', false)
            ->assertSee('wire:navigate', false);

        $this->get('/login')
            ->assertOk()
            ->assertSee('themes/kaev-aurelia/assets/css/app.css', false)
            ->assertSee(__('Username or email'));

        $this->assertDatabaseHas('cms_settings', [
            'key' => 'theme.active',
            'value' => 'kaev-aurelia',
        ]);
    }

    public function test_aurelia_scenery_has_the_correct_initial_state_without_javascript(): void
    {
        app(ThemeManager::class)->activate('kaev-aurelia');

        foreach (['/' => 'home', '/news' => 'page', '/about' => 'page', '/ru/pages/downloads' => 'page', '/login' => 'hidden'] as $path => $mode) {
            $this->get($path)->assertOk()
                ->assertSee('x-persist="aurelia-scene"', false)
                ->assertSee('data-aurelia-scene data-mode="'.$mode.'"', false);
        }
    }

    public function test_both_account_themes_hide_player_facing_build_metadata(): void
    {
        $user = User::factory()->create(['locale' => 'ru']);

        foreach (['luxury', 'kaev-aurelia'] as $theme) {
            app(AccountThemeManager::class)->activate($theme);
            $this->actingAs($user)->get('/account')->assertOk()
                ->assertSee(__('Back to website'))
                ->assertDontSee(__('Account theme'))
                ->assertDontSee(__('Version :version', ['version' => cms_version()]));
        }
    }

    public function test_account_aurelia_theme_can_be_activated_without_changing_public_theme(): void
    {
        $user = User::factory()->create([
            'name' => 'Aurelia Player',
            'email' => 'aurelia-player@example.test',
            'locale' => 'ru',
        ]);

        app(AccountThemeManager::class)->activate('kaev-aurelia');

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('account-themes/kaev-aurelia/assets/css/app.css', false)
            ->assertSee('assets/account/js/navigation.js', false)
            ->assertSee('data-account-sidebar', false)
            ->assertSee('data-account-topbar', false)
            ->assertDontSee('Kaev Aurelia Account');

        $this->actingAs($user)
            ->get('/account/game-accounts')
            ->assertOk()
            ->assertSee('Мои аккаунты')
            ->assertSee('account-panel-stats', false);

        $this->actingAs($user)
            ->get('/account/profile')
            ->assertOk()
            ->assertSee('Выбор аватара')
            ->assertSee('account-avatar-picker', false);

        $this->actingAs($user)
            ->get('/account/security')
            ->assertOk()
            ->assertSee('Безопасность аккаунта KaevCMS')
            ->assertSee('/account/security/password', false);

        $this->actingAs($user)
            ->get('/account/web-inventory')
            ->assertOk()
            ->assertSee('Веб-инвентарь')
            ->assertSee('account-surface', false);

        $this->assertDatabaseHas('cms_settings', [
            'key' => 'account_theme.active',
            'value' => 'kaev-aurelia',
        ]);
        $this->assertDatabaseMissing('cms_settings', [
            'key' => 'theme.active',
        ]);
    }

    public function test_aurelia_footer_keeps_configured_description_guest_actions_and_external_links(): void
    {
        app(ThemeManager::class)->activate('kaev-aurelia');
        $settings = app(CmsSettings::class);
        $settings->set('site.description.ru', 'Our project description');
        $settings->set(RegistrationSettings::KEY_ENABLED, '1');
        $settings->set(RegistrationSettings::KEY_REQUIRE_EMAIL_VERIFICATION, '0');
        $link = NavigationLink::query()->create([
            'url' => 'https://community.example.test',
            'is_active' => true,
            'show_in_header' => false,
            'show_in_footer' => true,
            'open_new_tab' => true,
            'sort_order' => 10,
        ]);
        $link->translations()->create(['locale' => 'ru', 'title' => 'Наше сообщество']);

        $html = $this->get('/ru')->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertSame(1, preg_match('/<footer\b[^>]*>.*?<\/footer>/s', $html, $matches));
        $footer = $matches[0];
        $this->assertStringContainsString('<h2>Our project description</h2>', $footer);
        $this->assertStringContainsString('Там, где эльфийские башни касаются облаков, а клинки звенят во имя чести.', $footer);
        foreach (['register', 'login', 'pages/downloads'] as $path) {
            $this->assertStringContainsString('href="'.url('/ru/'.$path).'"', $footer);
        }
        $this->assertMatchesRegularExpression('/href="https:\/\/community\.example\.test"\s+target="_blank"\s+rel="noopener noreferrer"/', $footer);
        $this->assertStringContainsString('Наше сообщество', $footer);
        $this->assertStringNotContainsString('KAEV AURELIA MMORPG', $footer);
    }

    public function test_aurelia_footer_hides_disabled_registration_and_shows_the_signed_in_account(): void
    {
        app(ThemeManager::class)->activate('kaev-aurelia');
        app(CmsSettings::class)->set(RegistrationSettings::KEY_ENABLED, '0');
        $guest = $this->get('/en')->assertOk()->getContent();
        $this->assertIsString($guest);
        $this->assertSame(1, preg_match('/<footer\b[^>]*>.*?<\/footer>/s', $guest, $matches));
        $this->assertStringNotContainsString('href="'.url('/en/register').'"', $matches[0]);
        $this->assertStringContainsString('Where elven towers touch the clouds, and blades ring in the name of honor.', $matches[0]);

        $signedIn = $this->actingAs(User::factory()->create())->get('/ru')->assertOk()->getContent();
        $this->assertIsString($signedIn);
        $this->assertSame(1, preg_match('/<footer\b[^>]*>.*?<\/footer>/s', $signedIn, $matches));
        $this->assertStringContainsString('href="'.url('/ru/account').'"', $matches[0]);
        $this->assertStringNotContainsString('href="'.url('/ru/login').'"', $matches[0]);
        $this->assertStringNotContainsString('footer-community', $matches[0]);
    }

    public function test_aurelia_theme_translation_keys_exist_in_both_bundled_languages(): void
    {
        $english = json_decode($this->readFile(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);
        $russian = json_decode($this->readFile(lang_path('ru.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($english);
        $this->assertIsArray($russian);

        $themeRoots = [
            base_path('themes/kaev-aurelia/views'),
            base_path('account-themes/kaev-aurelia/views'),
        ];

        foreach ($themeRoots as $themeRoot) {
            foreach (glob($themeRoot.'/**/*.blade.php') ?: [] as $view) {
                $this->assertTranslationKeysExist($view, $english, $russian);
            }

            foreach (glob($themeRoot.'/*.blade.php') ?: [] as $view) {
                $this->assertTranslationKeysExist($view, $english, $russian);
            }
        }
    }

    /** @return array<int, string> */
    private function publicThemeFiles(): array
    {
        return [
            'auth/forgot-password.blade.php',
            'auth/login.blade.php',
            'auth/register.blade.php',
            'auth/registration-disabled.blade.php',
            'auth/reset-password.blade.php',
            'auth/verify-email.blade.php',
            'home.blade.php',
            'layouts/app.blade.php',
            'news/index.blade.php',
            'news/show.blade.php',
            'pages/about.blade.php',
            'pages/downloads.blade.php',
            'pages/show.blade.php',
            'partials/footer.blade.php',
            'partials/header.blade.php',
            'statistics/index.blade.php',
        ];
    }

    /** @return array<int, string> */
    private function accountThemeFiles(): array
    {
        return [
            'components/character-row.blade.php',
            'characters/index.blade.php',
            'dashboard.blade.php',
            'game-accounts/create.blade.php',
            'game-accounts/index.blade.php',
            'game-accounts/show.blade.php',
            'layouts/app.blade.php',
            'livewire/character-directory.blade.php',
            'livewire/game-account-password-form.blade.php',
            'partials/navigation.blade.php',
            'partials/account-menu.blade.php',
            'partials/settings-tabs.blade.php',
            'profile/edit.blade.php',
            'security/edit.blade.php',
            'web-inventory/index.blade.php',
        ];
    }

    /**
     * @param  array<string, mixed>  $english
     * @param  array<string, mixed>  $russian
     */
    private function assertTranslationKeysExist(string $view, array $english, array $russian): void
    {
        $contents = $this->readFile($view);
        $keys = [];

        preg_match_all("/__\\(\\s*'((?:\\\\'|[^'])*)'/", $contents, $singleQuoted);
        preg_match_all('/__\\(\\s*"((?:\\\\"|[^"])*)"/', $contents, $doubleQuoted);

        foreach (array_merge($singleQuoted[1] ?? [], $doubleQuoted[1] ?? []) as $key) {
            $keys[] = stripcslashes($key);
        }

        foreach (array_unique($keys) as $key) {
            $this->assertArrayHasKey($key, $english, "Missing English translation [$key] used by $view.");
            $this->assertArrayHasKey($key, $russian, "Missing Russian translation [$key] used by $view.");
        }
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->fail("Unable to read file: $path");
        }

        return $contents;
    }
}
