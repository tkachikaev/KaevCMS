<?php

namespace App\Http\Middleware;

use App\Support\TrustedProxyConfiguration;
use Illuminate\Http\Middleware\TrustProxies as Middleware;

final class TrustProxies extends Middleware
{
    /**
     * @return list<string>|string|null
     */
    protected function proxies(): array|string|null
    {
        return TrustedProxyConfiguration::addresses(
            config('infrastructure.trusted_proxies'),
        );
    }

    protected function headers(): int
    {
        return TrustedProxyConfiguration::headers();
    }
}
