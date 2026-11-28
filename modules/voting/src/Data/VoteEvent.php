<?php

namespace KaevCMS\Modules\Voting\Data;

final readonly class VoteEvent
{
    public function __construct(
        public string $cursor,
        public string $eventId,
        public string $voteId,
        public string $projectId,
        public string $playerRef,
        public string $status,
        public string $votedAt,
        public string $statusChangedAt,
        public int $statusVersion,
    ) {}
}
