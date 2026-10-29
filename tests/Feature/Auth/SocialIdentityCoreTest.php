<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\Social\SocialAccountConflict;
use App\Services\Auth\Social\SocialAuthSettings;
use App\Services\Auth\Social\SocialFlowState;
use App\Services\Auth\Social\SocialIdentity;
use App\Services\Auth\Social\SocialIdentityProvider;
use App\Services\Auth\Social\SocialIdentityService;
use App\Services\RegistrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SocialIdentityCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_hides_unavailable_providers_but_keeps_existing_links(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user)->get('/account/security')->assertOk()
            ->assertDontSee(__('Connected social accounts'));

        $settings = app(SocialAuthSettings::class);
        $settings->updateCredentials('google', 'dummy-client', 'dummy-secret');
        $this->get('/account/security')->assertDontSee(__('Connected social accounts'));
        $settings->setEnabled('google', true);
        $this->get('/account/security')->assertOk()
            ->assertSee('/account/security/social/google/link', false)
            ->assertDontSee('/account/security/social/discord/link', false);

        app(SocialIdentityService::class)->link($user, new SocialIdentity('google', 'linked-player'), (int) $user->auth_session_version);
        $settings->setEnabled('google', false);
        $this->get('/account/security')->assertOk()
            ->assertSee(public_route('security.social.google.unlink'), false)
            ->assertDontSee('/account/security/social/google/link', false);
    }

    public function test_password_registration_records_a_real_password_and_allows_social_link_step_up(): void
    {
        app(RegistrationSettings::class)->update(true, false);
        $this->post('/register', ['name' => 'normalplayer', 'email' => 'normal@example.test',
            'password' => 'NormalPassword123!', 'password_confirmation' => 'NormalPassword123!'])
            ->assertRedirect(route('account'));
        $user = User::query()->where('email', 'normal@example.test')->firstOrFail();
        $this->assertNotNull($user->password_set_at);
        $this->assertSame(1, $user->auth_session_version);
        $settings = app(SocialAuthSettings::class);
        $settings->updateCredentials('google', 'dummy-client', 'dummy-secret');
        $settings->setEnabled('google', true);
        $this->post('/account/security/social/google/link', ['current_password' => 'NormalPassword123!'])
            ->assertOk()->assertViewIs('auth.social-continue');
    }

    public function test_new_user_session_version_matches_the_persisted_database_default(): void
    {
        $user = User::factory()->create();
        $this->assertSame(1, $user->auth_session_version);
        $this->assertSame($user->fresh()->auth_session_version, $user->auth_session_version);
    }

    public function test_existing_accounts_have_password_and_unique_social_identities(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->assertNotNull($first->password_set_at);
        $service = app(SocialIdentityService::class);
        $google = new SocialIdentity('google', 'google-subject-123', $second->email, true, true);

        $this->assertNull($service->findLinkedUser($google)); // Email is never an identity lookup.
        $linked = $service->link($first, $google, (int) $first->auth_session_version);
        $this->assertSame($first->id, $service->findLinkedUser($google)?->id);
        $this->assertSame($linked->id, $service->link($first, $google, (int) $first->auth_session_version)->id);

        $this->expectException(SocialAccountConflict::class);
        $service->link($second, $google, (int) $second->auth_session_version);
    }

    public function test_player_may_link_only_one_identity_per_provider(): void
    {
        $user = User::factory()->create();
        $service = app(SocialIdentityService::class);
        $service->link($user, new SocialIdentity('discord', 'discord-one'), (int) $user->auth_session_version);
        $this->expectException(SocialAccountConflict::class);
        $service->link($user, new SocialIdentity('discord', 'discord-two'), (int) $user->auth_session_version);
    }

    public function test_mock_provider_fulfills_the_common_adapter_contract(): void
    {
        $adapter = new class implements SocialIdentityProvider
        {
            public function name(): string
            {
                return 'google';
            }

            public function authorizationUrl(string $state, ?string $codeChallenge, ?string $nonce): string
            {
                return 'https://accounts.example.test/oauth?'.http_build_query(['state' => $state, 'code_challenge' => $codeChallenge, 'nonce' => $nonce]);
            }

            public function verifyAuthorizationCode(string $code, ?string $codeVerifier, ?string $nonce, array $callback = []): SocialIdentity
            {
                if ($code !== 'valid-test-code') {
                    throw new RuntimeException('Invalid test authorization code.');
                }

                return new SocialIdentity('google', 'stable-mock-subject', 'victim@example.com', true, false);
            }
        };

        $identity = $adapter->verifyAuthorizationCode('valid-test-code', 'verifier', 'nonce');
        $this->assertSame('google', $adapter->name());
        $this->assertSame('stable-mock-subject', $identity->providerUserId);
        $this->assertFalse($identity->emailOwnershipAssured);
        $this->assertNull(app(SocialIdentityService::class)->findLinkedUser($identity));
    }

    public function test_provider_allowlist_rejects_unknown_services(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SocialIdentity('evil-provider', 'subject');
    }

    public function test_an_external_unverified_email_cannot_claim_assured_ownership(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SocialIdentity('google', 'subject', 'victim@example.com', false, true);
    }

    public function test_password_recovery_version_blocks_stale_social_link(): void
    {
        $user = User::factory()->create();
        $before = (int) $user->auth_session_version;
        $user->increment('auth_session_version');
        $this->expectException(SocialAccountConflict::class);
        app(SocialIdentityService::class)->link($user, new SocialIdentity('google', 'old-callback'), $before);
    }

    public function test_starting_new_social_flow_invalidates_first_tab_and_consumes_state_once(): void
    {
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $flows = app(SocialFlowState::class);

        $old = $flows->start($request, 'google', 'login');
        $current = $flows->start($request, 'google', 'login');
        $this->assertNotSame($old->state, $current->state);
        try {
            $flows->consume($request, 'google', $old->state);
            $this->fail('The first tab should be invalidated.');
        } catch (RuntimeException) {
            // The old state was correctly rejected and the pending state consumed.
        }

        $this->expectException(RuntimeException::class);
        $flows->consume($request, 'google', $current->state);
    }

    public function test_valid_social_flow_can_be_consumed_only_once(): void
    {
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $flows = app(SocialFlowState::class);
        $state = $flows->start($request, 'telegram', 'login');
        $data = $flows->consume($request, 'telegram', $state->state);
        $this->assertSame('telegram', $data['provider']);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $data['code_verifier'], true)), '+/', '-_'), '=');
        $this->assertSame($state->codeChallenge, $challenge);
        $this->assertSame($state->nonce, $data['nonce']);
        $this->expectException(RuntimeException::class);
        $flows->consume($request, 'telegram', $state->state);
    }

    public function test_social_link_intent_fails_after_password_reset_session_bump(): void
    {
        $user = User::factory()->create();
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $flows = app(SocialFlowState::class);
        $state = $flows->start($request, 'discord', 'link', $user);
        $user->increment('auth_session_version');
        $this->expectException(RuntimeException::class);
        $flows->consume($request, 'discord', $state->state, $user);
    }
}
