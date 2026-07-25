<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\CmsSetting;
use App\Services\GameAccounts\GameAccountCredentialPolicy;
use App\Services\GameAccountSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GameAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_installation_uses_safe_compatible_game_account_defaults(): void
    {
        $settings = app(GameAccountSettings::class)->values();

        $this->assertSame(1, $settings['max_accounts']);
        $this->assertSame(4, $settings['login_min']);
        $this->assertFalse($settings['login_digit']);
        $this->assertArrayNotHasKey('login_lower', $settings);
        $this->assertArrayNotHasKey('login_upper', $settings);
        $this->assertSame(6, $settings['password_min']);
        $this->assertFalse($settings['password_lower']);
        $this->assertFalse($settings['password_upper']);
        $this->assertFalse($settings['password_digit']);
    }

    public function test_admin_can_open_and_update_game_account_settings(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/game-accounts')
            ->assertOk()
            ->assertSee('Игровые аккаунты')
            ->assertSee('settings-section-tabs', false)
            ->assertSee('Максимум аккаунтов на пользователя CMS')
            ->assertSee('data-game-account-limit-help', false)
            ->assertSee('Лимит считается суммарно по всем настроенным LoginServer.')
            ->assertSee('Временно недоступные игровые аккаунты также учитываются в лимите.')
            ->assertDontSee('name="login_lower"', false)
            ->assertDontSee('name="login_upper"', false)
            ->assertSee('name="password_lower"', false)
            ->assertSee('name="password_upper"', false);

        $this->actingAs($admin, 'admin')->put('/admin/settings/game-accounts', [
            'creation_enabled' => '1',
            'max_accounts' => 7,
            'login_min' => 5,
            'login_max' => 18,
            'login_digit' => '1',
            'password_min' => 10,
            'password_max' => 30,
            'password_lower' => '1',
            'password_upper' => '1',
            'password_digit' => '1',
        ])->assertRedirect(route('admin.settings.game-accounts'));

        $settings = app(GameAccountSettings::class)->values();
        $this->assertSame(7, $settings['max_accounts']);
        $this->assertSame(5, $settings['login_min']);
        $this->assertTrue($settings['login_digit']);
        $this->assertSame(10, $settings['password_min']);
        $this->assertTrue($settings['password_lower']);
        $this->assertTrue($settings['password_upper']);
        $this->assertTrue($settings['password_digit']);
    }

    public function test_game_account_settings_are_available_inside_the_settings_section(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'admin')->get('/admin/settings/game-accounts');

        $response
            ->assertOk()
            ->assertSeeInOrder([
                'Сайт',
                'Панель администратора',
                'Регистрация',
                'Игровые аккаунты',
                'Языки',
            ])
            ->assertSee('data-admin-settings-link', false)
            ->assertDontSee('data-admin-menu-group="system"', false);

        $html = $response->getContent();
        $serversStart = strpos($html, 'data-admin-menu-group="servers"');
        $usersStart = strpos($html, 'data-admin-menu-group="users"');

        $this->assertNotFalse($serversStart);
        $this->assertNotFalse($usersStart);
        $this->assertStringNotContainsString(
            route('admin.settings.game-accounts'),
            substr($html, $serversStart, $usersStart - $serversStart),
        );
    }

    public function test_legacy_hidden_login_case_requirements_are_ignored(): void
    {
        CmsSetting::query()->insert([
            [
                'key' => 'game_accounts.login_require_lowercase',
                'value' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'game_accounts.login_require_uppercase',
                'value' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame([], app(GameAccountCredentialPolicy::class)->loginErrors('PLAYER'));
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Main Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('Password123'),
            'is_active' => true,
        ]);
    }
}
