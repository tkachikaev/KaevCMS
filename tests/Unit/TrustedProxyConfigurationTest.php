<?php

namespace Tests\Unit;

use App\Support\TrustedProxyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class TrustedProxyConfigurationTest extends TestCase
{
    public function test_empty_configuration_does_not_trust_any_proxy(): void
    {
        $this->assertSame([
            'valid' => [],
            'invalid' => [],
            'trusts_all' => false,
        ], TrustedProxyConfiguration::parse(''));
        $this->assertNull(TrustedProxyConfiguration::addresses(''));
    }

    public function test_ip_addresses_and_cidr_ranges_are_parsed_and_deduplicated(): void
    {
        $value = '127.0.0.1, 10.0.0.0/8; 2001:db8::/32 127.0.0.1';

        $this->assertSame([
            'valid' => ['127.0.0.1', '10.0.0.0/8', '2001:db8::/32'],
            'invalid' => [],
            'trusts_all' => false,
        ], TrustedProxyConfiguration::parse($value));
        $this->assertSame(
            ['127.0.0.1', '10.0.0.0/8', '2001:db8::/32'],
            TrustedProxyConfiguration::addresses($value),
        );
    }

    public function test_invalid_entries_are_ignored_and_reported(): void
    {
        $configuration = TrustedProxyConfiguration::parse('10.0.0.1, not-an-ip, 10.0.0.0/99');

        $this->assertSame(['10.0.0.1'], $configuration['valid']);
        $this->assertSame(['not-an-ip', '10.0.0.0/99'], $configuration['invalid']);
        $this->assertFalse($configuration['trusts_all']);
        $this->assertSame(['10.0.0.1'], TrustedProxyConfiguration::addresses('10.0.0.1, not-an-ip'));
    }

    public function test_explicit_wildcard_trusts_all_proxies(): void
    {
        $configuration = TrustedProxyConfiguration::parse('*, 10.0.0.1');

        $this->assertTrue($configuration['trusts_all']);
        $this->assertSame('*', TrustedProxyConfiguration::addresses('*, 10.0.0.1'));
    }

    public function test_only_standard_forwarded_headers_are_trusted(): void
    {
        $this->assertSame(
            Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
            TrustedProxyConfiguration::headers(),
        );
    }
}
