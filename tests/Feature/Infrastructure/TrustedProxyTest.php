<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_forwarded_headers_are_ignored_when_no_proxy_is_trusted(): void
    {
        Route::get('/_test/trusted-proxy', static fn (Request $request): array => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.10',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/_test/trusted-proxy')
            ->assertOk()
            ->assertExactJson([
                'ip' => '198.51.100.20',
                'secure' => false,
            ]);
    }

    public function test_forwarded_headers_are_accepted_from_configured_proxy(): void
    {
        config()->set('infrastructure.trusted_proxies', '198.51.100.20');

        Route::get('/_test/configured-trusted-proxy', static fn (Request $request): array => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.10',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/_test/configured-trusted-proxy')
            ->assertOk()
            ->assertExactJson([
                'ip' => '203.0.113.10',
                'secure' => true,
            ]);
    }
}
