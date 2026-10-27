<?php

// Standalone checks for the FreeKassa callback peer/proxy matching logic.
// Does not require Laravel, Composer or external payment credentials.
namespace Illuminate\Http {
    final class Request
    {
        public object $headers;
        public function __construct(private readonly string $remote, ?string $forwarded = null)
        {
            $this->headers = new class($forwarded)
            {
                public function __construct(private readonly ?string $forwarded) {}
                public function get(string $name): ?string
                {
                    return $name === 'X-Forwarded-For' ? $this->forwarded : null;
                }
            };
        }
        public function server(string $name): ?string
        {
            return $name === 'REMOTE_ADDR' ? $this->remote : null;
        }
    }
}
namespace KaevCMS\Modules\Donations\Services {
    class DonationProviderSettings
    {
        public array $policy = [];
        public function freeKassaCallbackPolicy(): array
        {
            return $this->policy;
        }
    }
}
namespace {
    use Illuminate\Http\Request;
    use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
    use KaevCMS\Modules\Donations\Services\FreeKassaCallbackSecurity;

    require dirname(__DIR__, 3).'/modules/donations/src/Services/FreeKassaCallbackSecurity.php';
    function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new \RuntimeException($message);
        }
    }
    $settings = new DonationProviderSettings;
    $settings->policy = ['senders' => ['168.119.157.136'], 'proxies' => ['127.0.0.1', '203.0.113.0/24'], 'customized' => true];
    $guard = new FreeKassaCallbackSecurity($settings);
    check($guard->allows(new Request('168.119.157.136')), 'Direct merchant peer must work');
    check(! $guard->allows(new Request('198.51.100.90', '168.119.157.136')), 'Direct spoof must fail');
    check($guard->allows(new Request('127.0.0.1', '168.119.157.136')), 'Trusted nginx must work');
    check($guard->allows(new Request('203.0.113.23', '192.0.2.18, 168.119.157.136')), 'Earlier forged XFF must not override sender');
    check(!$guard->allows(new Request('127.0.0.1')), 'Proxy without forwarded chain must fail');
    check(!$guard->allows(new Request('127.0.0.1', '192.0.2.18, 198.51.100.9')), 'Spoofed sender must fail');
    check(!$guard->allows(new Request('127.0.0.1', '168.119.157.136, definitely-not-ip')), 'Invalid chain fails closed');
    check(!$guard->allows(new Request('127.0.0.1', '127.0.0.1')), 'All-trusted chain fails closed');
    check(! $guard->allows(new Request('::1', '168.119.157.136')), 'Undeclared proxy must fail');
    $settings->policy['senders'] = [];
    check(! $guard->allows(new Request('168.119.157.136')), 'Deleted sender is never restored');
    check(FreeKassaCallbackSecurity::parseSubmitted("168.119.157.136\n168.119.157.136\n") === ['168.119.157.136'], 'Deduplication');
    check(FreeKassaCallbackSecurity::parseSubmitted('') === [], 'Explicit removal');
    check(FreeKassaCallbackSecurity::parseSubmitted('203.0.113.0/24', true) === ['203.0.113.0/24'], 'Trusted proxy CIDR');
    foreach (['*', '999.1.1.1', '203.0.113.0/24', '1.2.3.4:1234'] as $bad) {
        try {
            FreeKassaCallbackSecurity::parseSubmitted($bad);
            throw new \RuntimeException('Unsafe sender value accepted: '.$bad);
        } catch (\InvalidArgumentException) {
            // expected
        }
    }
    echo "FreeKassa IP guard standalone checks OK\n";
}
