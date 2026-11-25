<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Models\UserGameAccount;
use App\Support\Themes\AccountThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_account_uses_a_persisted_livewire_shell_and_global_avatar_dialog(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->get('/account');

        $response
            ->assertOk()
            ->assertSee('data-account-sidebar', false)
            ->assertSee('data-account-topbar', false)
            ->assertSee('data-avatar-modal', false)
            ->assertSee('wire:navigate', false)
            ->assertSee('assets/account/js/navigation.js', false)
            ->assertSee('data-update-uri=', false);

        $response->assertSee('/account/profile', false)
            ->assertSee('/account/security', false)
            ->assertSee('/account/wallet', false);
    }

    public function test_characters_accounts_profile_and_security_are_available_on_default_and_localized_routes(): void
    {
        $this->get('/account/characters')->assertRedirect(route('login'));
        $this->get('/account/game-accounts')->assertRedirect(route('login'));
        $this->get('/account/profile')->assertRedirect(route('login'));
        $this->get('/account/security')->assertRedirect(route('login'));

        $user = $this->user();

        $this->actingAs($user)
            ->get('/account/characters')
            ->assertOk()
            ->assertSee('Мои персонажи')
            ->assertSee('wire:current="active"', false);

        $this->actingAs($user)
            ->get('/ru/account/characters')
            ->assertOk()
            ->assertSee('Мои персонажи');

        $this->actingAs($user)
            ->get('/account/game-accounts')
            ->assertOk()
            ->assertSee('Мои аккаунты')
            ->assertSee('wire:current="active"', false);

        $this->actingAs($user)
            ->get('/ru/account/game-accounts')
            ->assertOk()
            ->assertSee('Мои аккаунты');

        $this->actingAs($user)
            ->get('/account/profile')
            ->assertOk()
            ->assertSee('Настройки аккаунта')
            ->assertSee('Безопасность и пароль')
            ->assertSee('data-avatar-modal-open', false)
            ->assertDontSee('/account/security/password', false);

        $this->actingAs($user)
            ->get('/account/security')
            ->assertOk()
            ->assertSee('Безопасность аккаунта KaevCMS')
            ->assertSee('/account/security/password', false)
            ->assertDontSee('data-avatar-modal-open', false);

        $this->actingAs($user)
            ->get('/ru/account/security')
            ->assertOk()
            ->assertSee('Безопасность и пароль');
    }

    public function test_onboarding_only_appears_without_game_accounts(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('Обзор')
            ->assertSee('Создайте первый игровой аккаунт');

        UserGameAccount::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('Обзор')
            ->assertDontSee('Создайте первый игровой аккаунт');
    }

    public function test_bundled_account_themes_use_the_shared_character_rescue_modal_runtime(): void
    {
        $runtime = file_get_contents(public_path('assets/account/js/navigation.js'));
        $modal = file_get_contents(resource_path('views/components/account-character-rescue-modal.blade.php'));

        $this->assertIsString($runtime);
        $this->assertIsString($modal);
        $this->assertStringContainsString('data-character-rescue-open', $runtime);
        $this->assertStringContainsString('data-character-rescue-online', $runtime);
        $this->assertStringContainsString('data-character-rescue-modal', $modal);
        $this->assertStringContainsString('data-character-rescue-online-message', $modal);
        $this->assertStringContainsString('data-character-rescue-submit', $modal);

        foreach (['kaev-aurelia', 'kaev-classic', 'luxury'] as $theme) {
            $row = file_get_contents(base_path("account-themes/{$theme}/views/components/character-row.blade.php"));
            $directory = file_get_contents(base_path("account-themes/{$theme}/views/livewire/character-directory.blade.php"));

            $this->assertIsString($row);
            $this->assertIsString($directory);
            $this->assertStringContainsString('data-character-rescue-open', $row);
            $this->assertStringContainsString('data-character-rescue-online', $row);
            $this->assertStringContainsString("@if(\$character['rescue_visible'])", $row);
            $this->assertStringContainsString('<x-account-character-rescue-modal', $directory);
        }
    }

    public function test_all_bundled_account_themes_render_the_mobile_navigation_controls(): void
    {
        $user = $this->user();

        foreach (['kaev-aurelia', 'kaev-classic', 'luxury'] as $theme) {
            app(AccountThemeManager::class)->activate($theme);
            $this->actingAs($user)->get('/account')->assertOk()
                ->assertSee('data-account-sidebar-toggle', false)
                ->assertSee('data-account-sidebar-backdrop', false)
                ->assertSee('assets/account/js/navigation.js', false);
        }
    }

    private function user(): User
    {
        return User::factory()->create([
            'name' => 'Player Navigation',
            'email' => 'player-navigation@example.test',
            'locale' => 'ru',
        ]);
    }
}
