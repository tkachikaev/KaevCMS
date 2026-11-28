<?php

namespace KaevCMS\Modules\Voting\Data;

use Carbon\CarbonImmutable;

final readonly class VoteInvitation
{
    public function __construct(
        public string $result,
        public ?string $intentId = null,
        #[\SensitiveParameter] public ?string $url = null,
        public ?CarbonImmutable $expiresAt = null,
        public int $retryAfter = 0,
    ) {}
}
