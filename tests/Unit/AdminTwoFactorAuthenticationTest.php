<?php

namespace Tests\Unit;

use App\Models\Admin;
use App\Services\AdminTwoFactorAuthentication;
use Tests\TestCase;

class AdminTwoFactorAuthenticationTest extends TestCase
{
    public function test_totp_matches_rfc_6238_vectors_with_six_digits(): void
    {
        $service = app(AdminTwoFactorAuthentication::class);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertSame('287082', $service->codeAt($secret, 59));
        $this->assertSame('081804', $service->codeAt($secret, 1111111109));
        $this->assertSame('050471', $service->codeAt($secret, 1111111111));
        $this->assertSame('005924', $service->codeAt($secret, 1234567890));
        $this->assertSame('279037', $service->codeAt($secret, 2000000000));
        $this->assertSame('353130', $service->codeAt($secret, 20000000000));
    }

    public function test_provisioning_uri_contains_local_secret_and_standard_totp_parameters(): void
    {
        config()->set('app.name', 'KaevCMS');
        $service = app(AdminTwoFactorAuthentication::class);
        $administrator = new Admin(['email' => 'admin@example.com']);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $uri = $service->provisioningUri($administrator, $secret);
        $query = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertSame($secret, $query['secret'] ?? null);
        $this->assertSame('KaevCMS', $query['issuer'] ?? null);
        $this->assertSame('SHA1', $query['algorithm'] ?? null);
        $this->assertSame('6', $query['digits'] ?? null);
        $this->assertSame('30', $query['period'] ?? null);
    }

    public function test_verification_accepts_one_time_step_of_clock_drift(): void
    {
        $service = app(AdminTwoFactorAuthentication::class);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $timestamp = 1234567890;

        $this->assertTrue($service->verify($secret, $service->codeAt($secret, $timestamp - 30), $timestamp));
        $this->assertTrue($service->verify($secret, $service->codeAt($secret, $timestamp), $timestamp));
        $this->assertTrue($service->verify($secret, $service->codeAt($secret, $timestamp + 30), $timestamp));
        $this->assertFalse($service->verify($secret, $service->codeAt($secret, $timestamp - 60), $timestamp));
    }

    public function test_generated_secret_and_recovery_codes_have_expected_format(): void
    {
        $service = app(AdminTwoFactorAuthentication::class);
        $secret = $service->generateSecret();
        $codes = $service->generateRecoveryCodes();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/', $code);
        }
    }
}
