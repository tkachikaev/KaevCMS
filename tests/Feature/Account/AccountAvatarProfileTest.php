<?php

namespace Tests\Feature\Account;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Account\AccountAvatarCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountAvatarProfileTest extends TestCase
{
    use RefreshDatabase;

    private string $avatarRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->avatarRoot = storage_path('framework/testing/account-profile-avatars-'.Str::uuid());
        File::ensureDirectoryExists($this->avatarRoot);
        config()->set('cms.account_avatars.uploads_path', $this->avatarRoot);
        $this->app->forgetInstance(AccountAvatarCatalog::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->avatarRoot);

        parent::tearDown();
    }

    public function test_avatar_profile_requires_authentication_and_supports_localized_routes(): void
    {
        $this->get('/account/profile')->assertRedirect(route('login'));

        $user = $this->user();

        $this->actingAs($user)
            ->get('/ru/account/profile')
            ->assertOk()
            ->assertSee('Аватар профиля')
            ->assertSee('Изменить аватар')
            ->assertSee('Стандартный аватар');
    }

    public function test_player_can_select_an_avatar_from_the_modal_and_return_to_the_current_page(): void
    {
        File::put($this->avatarRoot.'/001-human-mage.webp', 'image');
        File::put($this->avatarRoot.'/readme.txt', 'ignored');
        $this->app->forgetInstance(AccountAvatarCatalog::class);
        $user = $this->user();

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('001-human-mage.webp', false)
            ->assertDontSee('readme.txt', false)
            ->assertSee('data-avatar-modal', false);

        $this->actingAs($user)
            ->put('/account/profile/avatar', [
                'avatar_filename' => '001-human-mage.webp',
                'return_to' => '/account/characters?server=all',
            ])
            ->assertRedirect('/account/characters?server=all')
            ->assertSessionHas('status', __('Avatar saved.'));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'avatar_filename' => '001-human-mage.webp',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'category' => 'user',
            'action' => 'user.account_avatar_updated',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'result' => 'success',
        ]);
        $audit = AuditLog::query()->where('action', 'user.account_avatar_updated')->sole();
        $this->assertSame(__('Account avatar changed'), $audit->actionLabel());

        $this->actingAs($user->refresh())
            ->get('/account')
            ->assertOk()
            ->assertSee('data-account-avatar', false)
            ->assertSee('/uploads/account-avatars/001-human-mage.webp', false);
    }

    public function test_player_cannot_select_an_unknown_or_unsafe_avatar(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->from('/account')
            ->put('/account/profile/avatar', [
                'avatar_filename' => '../outside.webp',
                'return_to' => '/account',
            ])
            ->assertRedirect('/account')
            ->assertSessionHasErrors('avatar_filename');

        $this->actingAs($user)
            ->from('/account')
            ->put('/account/profile/avatar', [
                'avatar_filename' => ['unsafe.webp'],
                'return_to' => '/account',
            ])
            ->assertRedirect('/account')
            ->assertSessionHasErrors('avatar_filename');

        $this->assertNull($user->refresh()->avatar_filename);
        $this->assertSame(0, AuditLog::query()->where('action', 'user.account_avatar_updated')->count());
    }

    public function test_avatar_update_rejects_external_or_malformed_return_paths(): void
    {
        File::put($this->avatarRoot.'/003-orc.webp', 'image');
        $this->app->forgetInstance(AccountAvatarCatalog::class);
        $user = $this->user();

        foreach (['https://example.test/steal', '//example.test/steal', '/account\\profile', '/admin', '/%2F%2Fexample.test/steal', '/account%0d%0aX-Test:1'] as $unsafeReturnTo) {
            $this->actingAs($user)
                ->put('/account/profile/avatar', [
                    'avatar_filename' => '003-orc.webp',
                    'return_to' => $unsafeReturnTo,
                ])
                ->assertRedirect(public_route('account'));
        }
    }

    public function test_localized_avatar_update_returns_to_the_requested_localized_page(): void
    {
        File::put($this->avatarRoot.'/004-elf.webp', 'image');
        $this->app->forgetInstance(AccountAvatarCatalog::class);
        $user = $this->user();

        $this->actingAs($user)
            ->put('/ru/account/profile/avatar', [
                'avatar_filename' => '004-elf.webp',
                'return_to' => '/ru/account/characters',
            ])
            ->assertRedirect('/ru/account/characters');

        $this->assertSame('004-elf.webp', $user->refresh()->avatar_filename);
    }

    public function test_player_can_restore_the_default_avatar_and_missing_files_fall_back_safely(): void
    {
        File::put($this->avatarRoot.'/002-elf.png', 'image');
        $this->app->forgetInstance(AccountAvatarCatalog::class);
        $user = $this->user(['avatar_filename' => '002-elf.png']);

        File::delete($this->avatarRoot.'/002-elf.png');
        $this->app->forgetInstance(AccountAvatarCatalog::class);

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertDontSee('/uploads/account-avatars/002-elf.png', false)
            ->assertSee('data-account-avatar', false);

        $this->actingAs($user)
            ->put('/account/profile/avatar', [
                'avatar_filename' => '',
                'return_to' => '/account',
            ])
            ->assertRedirect('/account');

        $this->assertNull($user->refresh()->avatar_filename);
    }

    /** @param array<string,mixed> $attributes */
    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Avatar Player',
            'email' => 'avatar-player@example.test',
            'locale' => 'ru',
        ], $attributes));
    }
}
