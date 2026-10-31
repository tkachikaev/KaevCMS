<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Services\Auth\Social\SocialAuthSettings;
use App\Services\RegistrationSettings;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use KaevCMS\Modules\Referrals\Models\ReferralCode;
use KaevCMS\Modules\Referrals\Models\ReferralInvite;
use KaevCMS\Modules\Referrals\Models\ReferralSetting;
use KaevCMS\Modules\Referrals\Services\ReferralInvitationService;
use Tests\TestCase;

class ReferralInvitationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('referrals');
        app(ModuleRuntime::class)->bootModule($module);
        ReferralSetting::query()->where('setting_key', 'global')->update(['enabled' => true]);
        app(RegistrationSettings::class)->update(true, false);
    }

    public function test_registration_with_encrypted_invitation_cookie_creates_exactly_one_link_and_clears_cookie(): void
    {
        $inviter = User::factory()->create();
        $cookie = $this->invitationCookie($inviter);
        $response = $this->withCookie(ReferralInvitationService::COOKIE_NAME, $cookie)->post('/register', [
            'name' => 'invitedperson',
            'email' => 'invitedperson@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);
        $response->assertRedirect();
        $invitee = User::query()->where('name', 'invitedperson')->firstOrFail();
        $this->assertDatabaseHas('module_referral_invites', [
            'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee->id,
            'status' => 'active',
        ]);
        $this->assertSame(1, ReferralInvite::query()->count());
        $this->assertCookieCleared($response);
    }

    public function test_invitation_cookie_is_opaque_httponly_lax_and_expires_seven_days_after_link_visit(): void
    {
        $inviter = User::factory()->create();
        $code = app(ReferralInvitationService::class)->codeFor($inviter);
        $response = $this->withServerVariables(['HTTPS' => 'on'])->get('/r/'.$code);
        $response->assertOk()->assertSee(__('module-referrals::messages.invitation_title'));
        $cookies = $response->headers->getCookies();
        $cookie = $this->findCookie($cookies);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertTrue($cookie->isSecure());
        $this->assertStringNotContainsString($code, $cookie->getValue());
        $this->assertGreaterThan(now()->addDays(6)->timestamp, $cookie->getExpiresTime());
        $this->assertLessThanOrEqual(now()->addDays(7)->addMinute()->timestamp, $cookie->getExpiresTime());
    }

    public function test_expired_invitation_does_not_attach_when_new_user_registers(): void
    {
        $inviter = User::factory()->create();
        $cookie = $this->invitationCookie($inviter);
        $this->travel(8)->days();
        $this->withCookie(ReferralInvitationService::COOKIE_NAME, $cookie)->post('/register', [
            'name' => 'laterperson',
            'email' => 'laterperson@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertRedirect();
        $this->assertDatabaseCount('module_referral_invites', 0);
    }

    public function test_latest_valid_invitation_wins_and_invalid_link_does_not_overwrite_it(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $firstCookie = $this->invitationCookie($first);
        $secondCode = app(ReferralInvitationService::class)->codeFor($second);
        $secondResponse = $this->withCookie(ReferralInvitationService::COOKIE_NAME, $firstCookie)
            ->get('/r/'.$secondCode)->assertOk();
        $secondCookie = $this->findCookie($secondResponse->headers->getCookies())->getValue();
        $this->get('/r/'.Str::random(40))->assertNotFound();
        $this->withCookie(ReferralInvitationService::COOKIE_NAME, $secondCookie)->post('/register', [
            'name' => 'switchperson',
            'email' => 'switchperson@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertRedirect();
        $invite = ReferralInvite::query()->firstOrFail();
        $this->assertSame($second->id, $invite->inviter_user_id);
    }

    public function test_revoked_inactive_and_disabled_inviter_links_are_not_accepted(): void
    {
        $inviter = User::factory()->create();
        $code = app(ReferralInvitationService::class)->codeFor($inviter);
        $this->get('/r/'.$code)->assertOk();
        ReferralCode::query()->where('code', $code)->update(['revoked_at' => now()]);
        $this->get('/r/'.$code)->assertNotFound();
        ReferralCode::query()->where('code', $code)->update(['revoked_at' => null]);
        $inviter->forceFill(['is_active' => false])->save();
        $this->get('/r/'.$code)->assertNotFound();
        $inviter->forceFill(['is_active' => true])->save();
        ReferralSetting::query()->where('setting_key', 'global')->update(['enabled' => false]);
        $this->get('/r/'.$code)->assertNotFound();
    }

    public function test_existing_password_login_and_cancel_both_clear_invitation_without_attaching(): void
    {
        $inviter = User::factory()->create();
        $existing = User::factory()->create(['name' => 'existinguser']);
        $cookie = $this->invitationCookie($inviter);
        $this->withCookie(ReferralInvitationService::COOKIE_NAME, $cookie)
            ->post('/modules/referrals/cancel')->assertRedirect();
        $response = $this->withCookie(ReferralInvitationService::COOKIE_NAME, $cookie)->post('/login', [
            'login' => $existing->email,
            'password' => 'Password123',
        ]);
        $response->assertRedirect();
        $this->assertCookieCleared($response);
        $this->assertDatabaseCount('module_referral_invites', 0);
    }

    public function test_social_registration_attaches_invitation_after_pending_email_flow_without_session_link(): void
    {
        $inviter = User::factory()->create();
        $cookie = $this->invitationCookie($inviter);
        app(SocialAuthSettings::class)->updateCredentials('google', 'test-client', 'test-secret');
        app(SocialAuthSettings::class)->setEnabled('google', true);
        $this->withSession(['social_auth.registration' => [
            'provider' => 'google',
            'subject' => 'unlinked-provider-subject',
            'email' => 'socialinvited@example.test',
            'assured' => true,
            'issued' => now()->timestamp,
            'email_verification_hash' => null,
        ]]);
        $this->get('/auth/social/google/register')->assertOk();
        $response = $this->withCookie(ReferralInvitationService::COOKIE_NAME, $cookie)
            ->post('/auth/social/google/register', ['name' => 'socialinvited']);
        $response->assertRedirect(route('account'));
        $user = User::query()->where('name', 'socialinvited')->firstOrFail();
        $this->assertDatabaseHas('module_referral_invites', ['inviter_user_id' => $inviter->id, 'invitee_user_id' => $user->id]);
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'google']);
        $this->assertCookieCleared($response);
    }

    public function test_social_registration_cannot_bind_an_existing_email_or_a_reused_identity(): void
    {
        $inviter = User::factory()->create();
        $existing = User::factory()->create(['email' => 'alreadyregistered@example.test']);
        $cookie = $this->invitationCookie($inviter);
        app(SocialAuthSettings::class)->updateCredentials('google', 'test-client', 'test-secret');
        app(SocialAuthSettings::class)->setEnabled('google', true);
        $this->withSession(['social_auth.registration' => [
            'provider' => 'google',
            'subject' => 'external-new-identity',
            'email' => $existing->email,
            'assured' => true,
            'issued' => now()->timestamp,
            'email_verification_hash' => null,
        ]]);
        $this->withCookie(ReferralInvitationService::COOKIE_NAME, $cookie)
            ->post('/auth/social/google/register', ['name' => 'differentname'])
            ->assertRedirect(public_route('login'));
        $this->assertDatabaseCount('module_referral_invites', 0);
        $this->assertDatabaseCount('user_social_accounts', 0);
    }

    public function test_creating_a_user_outside_registration_never_attaches_and_disabled_module_does_not_process_invitation(): void
    {
        $inviter = User::factory()->create();
        $cookie = $this->invitationCookie($inviter);
        User::factory()->create();
        $this->assertDatabaseCount('module_referral_invites', 0);
        $code = ReferralCode::query()->where('user_id', $inviter->id)->value('code');
        app(ModuleManager::class)->disable('referrals');
        $this->get('/r/'.$code)->assertNotFound();
        $this->withCookie(ReferralInvitationService::COOKIE_NAME, $cookie)->post('/register', [
            'name' => 'afterdisable',
            'email' => 'afterdisable@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertRedirect();
        $this->assertDatabaseCount('module_referral_invites', 0);
    }

    private function invitationCookie(User $inviter): string
    {
        $code = app(ReferralInvitationService::class)->codeFor($inviter);
        $response = $this->get('/r/'.$code)->assertOk();

        return $this->findCookie($response->headers->getCookies())->getValue();
    }

    /** @param list<\Symfony\Component\HttpFoundation\Cookie> $cookies */
    private function findCookie(array $cookies): \Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === ReferralInvitationService::COOKIE_NAME) {
                return $cookie;
            }
        }
        $this->fail('Referral invitation cookie was not sent.');
    }

    private function assertCookieCleared(TestResponse $response): void
    {
        $cookie = $this->findCookie($response->headers->getCookies());
        $this->assertLessThan(now()->timestamp, $cookie->getExpiresTime());
    }
}
