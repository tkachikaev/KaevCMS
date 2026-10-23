<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_receive_safe_non_breaking_headers(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        $policy = $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("form-action 'self' https://yoomoney.ru", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
        $this->assertFalse($this->get('/news')->headers->hasCacheControlDirective('no-store'));
    }

    public function test_authenticated_pages_and_json_cannot_be_stored_or_revalidated(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['/account/wallet', '/en/account/profile', '/ru/account/security', '/news'] as $path) {
            $this->get($path)->assertOk()
                ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
                ->assertHeader('Pragma', 'no-cache');
        }

        // A module or Livewire endpoint need not live under /account.
        Route::middleware(['web', 'auth'])->post('/security-test/reactive', fn () => response()
            ->json(['balance' => 42])->setEtag('private-balance')
            ->setLastModified(new \DateTimeImmutable)->header('Cache-Control', 'public, max-age=3600, s-maxage=3600, no-transform'));
        $this->postJson('/security-test/reactive')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, no-transform, private')
            ->assertHeaderMissing('ETag')->assertHeaderMissing('Last-Modified');
    }

    public function test_login_and_logout_responses_are_not_stored(): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);
        foreach (['/login', '/en/register', '/ru/forgot-password', '/reset-password/'.$token.'?email='.$user->email] as $path) {
            $this->get($path)->assertOk()
                ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
        }
        $this->get('/reset-password/invalid-token')->assertRedirect()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
        $this->actingAs($user)->post('/logout')->assertRedirect()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
    }

    public function test_a_public_endpoint_which_signs_in_also_receives_no_store(): void
    {
        $user = User::factory()->create();
        Route::middleware('web')->post('/security-test/sign-in', function () use ($user) {
            auth('web')->login($user);

            return response('Signed in');
        });
        $this->post('/security-test/sign-in')->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
    }

    public function test_admin_headers_keep_the_stricter_frame_policy(): void
    {
        $admin = Admin::factory()->create();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Content-Security-Policy', "default-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    }
}
