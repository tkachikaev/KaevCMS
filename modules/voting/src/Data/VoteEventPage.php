<?php

namespace KaevCMS\Modules\Voting\Data;

final readonly class VoteEventPage
{
    /** @param list<VoteEvent> $events */
    public function __construct(
        public string $result,
        public array $events = [],
        public ?string $nextCursor = null,
        public bool $hasMore = false,
        public int $retryAfter = 0,
    ) {}
}
