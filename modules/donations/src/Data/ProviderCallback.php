<?php

namespace KaevCMS\Modules\Donations\Data;

/** Transport-neutral inbound callback, before provider signature verification. */
final readonly class ProviderCallback
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $rawBody,
        public array $headers,
        public string $queryString,
    ) {}
}
