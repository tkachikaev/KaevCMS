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

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'same-origin');

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression(
            "/\\Adefault-src 'self'; style-src 'self' 'nonce-([A-Za-z0-9]{40})'; img-src 'self' data: blob:; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'\\z/",
            $policy,
        );
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $nonce);
        // Livewire's own style block carries this response's nonce; nothing else may be inline.
        $this->assertStringContainsString('<style nonce="'.$nonce[1].'" data-livewire-style>', (string) $response->getContent());
        $this->assertStringNotContainsString('unsafe-inline', $policy);

        $next = (string) $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString($nonce[1], $next);
    }

    public function test_templates_do_not_use_inline_handlers_blocked_by_the_policy(): void
    {
        $handler = '/\son(?:click|change|input|submit|reset|load|error|focus|blur|select|toggle|scroll|key\w+|mouse\w+|pointer\w+)\s*=|javascript:/i';
        $offenders = [];

        foreach (['resources/views', 'themes', 'account-themes', 'modules'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php') && preg_match($handler, (string) file_get_contents($file->getPathname())) === 1) {
                    $offenders[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                }
            }
        }

        $this->assertSame([], $offenders, 'Inline handlers are silently blocked by CSP; attach behavior from a script file.');
    }
}
