<?php

namespace KaevCMS\Modules\Voting\Services;

use InvalidArgumentException;
use KaevCMS\Modules\Voting\Contracts\VotingProvider;
use KaevCMS\Modules\Voting\Providers\KTreonProvider;

final class VotingProviderRegistry
{
    /** @return array<string, VotingProvider> */
    public function all(): array
    {
        $provider = app(KTreonProvider::class);

        return [$provider->code() => $provider];
    }

    public function get(string $code): VotingProvider
    {
        return $this->all()[$code] ?? throw new InvalidArgumentException('Unknown voting provider.');
    }
}
