<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CmsSetting;
use App\Models\User;
use App\Services\Auth\Social\SocialAuthSettings;
use App\Services\Auth\Social\SocialIdentity;
use App\Services\Auth\Social\SocialIdentityService;
use App\Services\Auth\Social\SocialProviders;
use App\Services\Auth\Social\TelegramIdTokenVerifier;
use App\Services\CmsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SocialProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://cms.example.test');
    }

    public function test_implemented_providers_report_that_social_sign_in_is_enabled(): void
    {
        $admin = $this->admin();
        foreach (['discord', 'facebook'] as $provider) {
            $settings = app(SocialAuthSettings::class);
            $settings->updateCredentials($provider, 'test-client', 'test-secret');
            $this->actingAs($admin, 'admin')
                ->post('/admin/settings/registration/social/'.$provider.'/toggle', ['enabled' => '1'])
                ->assertRedirect()
                ->assertSessionHas('status', __('Social sign-in provider enabled.'));
        }
    }

    public function test_all_six_providers_start_disabled_and_are_visible_on_existing_registration_page(): void
    {
        $admin = $this->admin();
        $page = $this->actingAs($admin, 'admin')->get('/admin/settings/registration')->assertOk();

        foreach (SocialProviders::all() as $provider) {
            $page->assertSee('/auth/social/'.$provider.'/callback');
            $page->assertSee('/settings/registration/social/'.$provider, false);
            $this->assertFalse(app(SocialAuthSettings::class)->isAvailable($provider));
        }
        $page->assertSee('Социальная авторизация');
        $this->assertDatabaseMissing('cms_settings', ['key' => 'social_auth.google.enabled', 'value' => '1']);
    }

    public function test_provider_credentials_are_encrypted_and_never_rendered_even_after_validation_failure(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->put('/admin/settings/registration/social/google', [
            'client_id' => 'google-app-id',
            'client_secret' => 'UltraSensitivePassword#123',
        ])->assertRedirect(route('admin.settings.registration'));

        $encrypted = (string) CmsSetting::query()->where('key', 'social_auth.google.client_secret_encrypted')->value('value');
        $this->assertNotSame('UltraSensitivePassword#123', $encrypted);
        $this->assertSame('UltraSensitivePassword#123', Crypt::decryptString($encrypted));

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/registration')
            ->assertOk()
            ->assertSee('google-app-id')
            ->assertDontSee('UltraSensitivePassword#123')
            ->assertDontSee($encrypted);
        $this->actingAs($admin, 'admin')
            ->put('/admin/settings/registration/social/google', [
                'client_id' => 'google-app-id',
                'client_secret' => '',
            ])->assertRedirect();
        $this->assertSame($encrypted, CmsSetting::query()->where('key', 'social_auth.google.client_secret_encrypted')->value('value'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.social_provider_updated', 'result' => 'success']);
        $this->assertStringNotContainsString('UltraSensitivePassword#123', AuditLog::query()->get()->toJson());
    }

    public function test_validation_failure_does_not_flash_plaintext_secret_to_session(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->from('/admin/settings/registration')
            ->put('/admin/settings/registration/social/google', [
                'client_id' => '',
                'client_secret' => 'SensitiveSecretNotForSession',
            ])
            ->assertRedirect('/admin/settings/registration')
            ->assertSessionHasErrors('client_id')
            ->assertSessionMissing('_old_input.client_secret');
    }

    public function test_provider_must_be_configured_before_enabling_and_vk_uses_pkce_without_secret(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->post('/admin/settings/registration/social/google/toggle', ['enabled' => '1'])
            ->assertSessionHasErrors('enabled');
        $this->assertFalse(app(SocialAuthSettings::class)->isAvailable('google'));

        $this->actingAs($admin, 'admin')->put('/admin/settings/registration/social/vk', [
            'client_id' => 'vk-app-id',
        ])->assertRedirect();
        $this->actingAs($admin, 'admin')->post('/admin/settings/registration/social/vk/toggle', ['enabled' => '1'])
            ->assertRedirect();
        $this->assertTrue(app(SocialAuthSettings::class)->isAvailable('vk'));
        $this->assertNull(app(SocialAuthSettings::class)->credentialsFor('google'));
        $this->assertSame('vk-app-id', app(SocialAuthSettings::class)->credentialsFor('vk')['client_id']);
        $this->assertNull(app(SocialAuthSettings::class)->credentialsFor('vk')['client_secret']);
    }

    public function test_disabling_or_rotating_id_preserves_links_but_blocks_social_flow(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->put('/admin/settings/registration/social/discord', [
            'client_id' => 'client-one', 'client_secret' => 'secret-one',
        ])->assertRedirect();
        $this->actingAs($admin, 'admin')->post('/admin/settings/registration/social/discord/toggle', ['enabled' => '1'])
            ->assertRedirect();
        $this->assertTrue(app(SocialAuthSettings::class)->isAvailable('discord'));
        $player = User::factory()->create();
        app(SocialIdentityService::class)->link($player, new SocialIdentity('discord', 'stable-player-id'), (int) $player->auth_session_version);
        $this->actingAs($admin, 'admin')->put('/admin/settings/registration/social/discord', [
            'client_id' => 'client-two', 'client_secret' => '',
        ])->assertRedirect();
        $this->assertFalse(app(SocialAuthSettings::class)->isAvailable('discord'));
        $this->assertDatabaseHas('cms_settings', ['key' => 'social_auth.discord.enabled', 'value' => '0']);
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $player->id, 'provider' => 'discord', 'provider_user_id' => 'stable-player-id']);
    }

    public function test_invalid_ciphertext_and_insecure_app_url_fail_closed(): void
    {
        // Simulate an installed verifier so this test isolates the encryption failure.
        $this->app->instance(TelegramIdTokenVerifier::class,
            new class extends TelegramIdTokenVerifier
            {
                public function available(): bool
                {
                    return true;
                }
            });
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->put('/admin/settings/registration/social/telegram', [
            'client_id' => 'telegram-app', 'client_secret' => 'correct-secret',
        ])->assertRedirect();
        $this->actingAs($admin, 'admin')->post('/admin/settings/registration/social/telegram/toggle', ['enabled' => '1'])
            ->assertRedirect();

        CmsSetting::query()->where('key', 'social_auth.telegram.client_secret_encrypted')->update(['value' => 'corrupted']);
        app(CmsSettings::class)->forget();
        $this->assertFalse(app(SocialAuthSettings::class)->isAvailable('telegram'));
        $this->assertSame('invalid', app(SocialAuthSettings::class)->values('telegram')['status']);

        config()->set('app.url', 'http://example.test');
        $this->assertNull(app(SocialAuthSettings::class)->callbackUrl('google'));
        $this->actingAs($admin, 'admin')->post('/admin/settings/registration/social/vk/toggle', ['enabled' => '1'])
            ->assertSessionHasErrors('enabled');
    }

    public function test_callback_is_based_on_trusted_app_url_not_the_request_host(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->withServerVariables(['HTTP_HOST' => 'attacker.example'])
            ->get('/admin/settings/registration')
            ->assertOk()
            ->assertSee('https://cms.example.test/auth/social/google/callback')
            ->assertDontSee('https://attacker.example/auth/social/google/callback');
    }

    public function test_secret_removal_is_explicit_and_disables_provider(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->put('/admin/settings/registration/social/yandex', [
            'client_id' => 'yandex-id', 'client_secret' => 'secret-value',
        ])->assertRedirect();
        $this->actingAs($admin, 'admin')->post('/admin/settings/registration/social/yandex/toggle', ['enabled' => '1'])
            ->assertRedirect();
        $this->actingAs($admin, 'admin')->delete('/admin/settings/registration/social/yandex/secret')
            ->assertRedirect();
        $this->assertFalse(app(SocialAuthSettings::class)->isAvailable('yandex'));
        $this->assertFalse(app(SocialAuthSettings::class)->values('yandex')['secret_saved']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'social_auth.yandex.enabled', 'value' => '0']);
    }

    public function test_provider_forms_are_isolated_from_registration_settings_and_unknown_provider_is_rejected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->put('/admin/settings/registration', [
            'registration_enabled' => '1', 'email_verification_required' => '0',
        ])->assertRedirect();
        $this->actingAs($admin, 'admin')->put('/admin/settings/registration/social/google', [
            'client_id' => 'valid-id', 'client_secret' => 'correct-secret',
        ])->assertRedirect();
        $this->assertDatabaseHas('cms_settings', ['key' => 'registration.enabled', 'value' => '1']);

        $this->actingAs($admin, 'admin')->put('/admin/settings/registration/social/unknown', [
            'client_id' => 'x', 'client_secret' => 'x',
        ])->assertNotFound();
        $this->assertDatabaseMissing('cms_settings', ['key' => 'social_auth.unknown.client_id']);
    }

    public function test_non_admin_and_editor_cannot_update_or_toggle_social_providers(): void
    {
        $this->put('/admin/settings/registration/social/google', ['client_id' => 'x'])->assertRedirect();
        $editor = $this->admin(AdminRole::Editor);
        $this->actingAs($editor, 'admin')->put('/admin/settings/registration/social/google', [
            'client_id' => 'x', 'client_secret' => 'x',
        ])->assertForbidden();
        $this->actingAs($editor, 'admin')->post('/admin/settings/registration/social/google/toggle', ['enabled' => '1'])
            ->assertForbidden();
        $this->assertDatabaseMissing('cms_settings', ['key' => 'social_auth.google.client_id']);
    }

    private function admin(AdminRole $role = AdminRole::Owner): Admin
    {
        return Admin::query()->create([
            'name' => 'Social Admin',
            'email' => 'social-admin@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
            'role' => $role,
        ]);
    }
}
