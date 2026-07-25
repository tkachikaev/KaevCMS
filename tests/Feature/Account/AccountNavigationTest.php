<?php

namespace Tests\Feature\Account;

use App\Models\User;
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
            ->assertSee('data-avatar-modal-open', false)
            ->assertSee('wire:navigate', false)
            ->assertSee('data-navigate-track', false)
            ->assertSee('data-navigate-once="true"', false)
            ->assertSee('account-themes/luxury/assets/js/navigation.js', false)
            ->assertSee('data-update-uri=', false);

        $themePath = app(AccountThemeManager::class)->themePath();
        $layout = file_get_contents($themePath.'/views/layouts/app.blade.php');
        $navigation = file_get_contents($themePath.'/views/partials/navigation.blade.php');
        $script = file_get_contents(public_path('account-themes/luxury/assets/js/navigation.js'));
        $styles = file_get_contents(public_path('account-themes/luxury/assets/css/app.css'));

        $this->assertIsString($layout);
        $this->assertIsString($navigation);
        $this->assertIsString($script);
        $this->assertIsString($styles);
        $this->assertStringContainsString("@persist('account-sidebar')", $layout);
        $this->assertStringContainsString("@persist('account-topbar')", $layout);
        $this->assertStringContainsString('wire:navigate:scroll', $layout);
        $this->assertStringContainsString('account_theme_asset', $layout);
        $this->assertStringContainsString('<x-account-avatar-modal', $layout);
        $this->assertStringContainsString("public_route('profile.edit')", $layout);
        $this->assertStringNotContainsString("public_route('characters.index')", $layout);
        $this->assertStringNotContainsString("public_route('game-accounts.index')", $layout);
        $this->assertStringNotContainsString("public_route('web-inventory.index')", $layout);
        $this->assertStringContainsString("public_route('characters.index')", $navigation);
        $this->assertStringNotContainsString("public_route('profile.edit')", $navigation);
        $this->assertStringContainsString('wire:navigate.hover', $navigation);
        $this->assertStringContainsString('wire:current.exact="active"', $navigation);
        $this->assertStringContainsString('livewire:navigate', $script);
        $this->assertStringContainsString('livewire:navigated', $script);
        $this->assertStringContainsString('showModal()', $script);
        $this->assertStringContainsString('data-avatar-modal-open', $script);
        $this->assertStringContainsString('account-is-navigating', $script);
        $this->assertStringContainsString('html.account-is-navigating .account-content', $styles);
        $this->assertStringContainsString('.account-avatar-modal', $styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
    }

    public function test_characters_accounts_and_profile_are_available_on_default_and_localized_routes(): void
    {
        $this->get('/account/characters')->assertRedirect(route('login'));
        $this->get('/account/game-accounts')->assertRedirect(route('login'));
        $this->get('/account/profile')->assertRedirect(route('login'));

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
            ->assertSee('Аватар профиля')
            ->assertSee('data-avatar-modal-open', false);
    }

    public function test_account_theme_views_use_livewire_navigation_links(): void
    {
        $themePath = base_path('account-themes/luxury/views');
        $views = [
            $themePath.'/dashboard.blade.php',
            $themePath.'/characters/index.blade.php',
            $themePath.'/profile/edit.blade.php',
            $themePath.'/game-accounts/index.blade.php',
            $themePath.'/game-accounts/create.blade.php',
            $themePath.'/game-accounts/show.blade.php',
            $themePath.'/livewire/character-directory.blade.php',
        ];

        foreach ($views as $view) {
            $contents = file_get_contents($view);

            $this->assertIsString($contents);
            $this->assertStringContainsString('wire:navigate', $contents, $view);
        }
    }

    public function test_profile_avatar_is_not_reused_as_a_game_account_icon(): void
    {
        foreach (['kaev-aurelia', 'luxury'] as $theme) {
            $index = file_get_contents(base_path("account-themes/{$theme}/views/game-accounts/index.blade.php"));
            $show = file_get_contents(base_path("account-themes/{$theme}/views/game-accounts/show.blade.php"));
            $dashboard = file_get_contents(base_path("account-themes/{$theme}/views/dashboard.blade.php"));
            $directory = file_get_contents(base_path("account-themes/{$theme}/views/livewire/character-directory.blade.php"));

            $this->assertIsString($index);
            $this->assertIsString($show);
            $this->assertIsString($dashboard);
            $this->assertIsString($directory);
            $this->assertStringContainsString('<x-game-account-icon', $index);
            $this->assertStringContainsString('<x-game-account-icon', $show);
            $this->assertStringNotContainsString(':fallback="$account->game_login"', $index);
            $this->assertStringNotContainsString(':fallback="$account->game_login"', $show);
            $this->assertStringContainsString('<x-game-account-icon class="account-group-account-icon"', $directory);
            $this->assertStringNotContainsString('mb_substr($account[\'login\']', $directory);
            $this->assertStringNotContainsString('<livewire:account.character-directory', $dashboard);
        }
    }

    public function test_legacy_core_account_views_and_assets_are_not_used(): void
    {
        $this->assertDirectoryDoesNotExist(resource_path('views/account'));
        $this->assertDirectoryDoesNotExist(resource_path('views/livewire/account'));
        $this->assertDirectoryDoesNotExist(public_path('assets/account'));
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
