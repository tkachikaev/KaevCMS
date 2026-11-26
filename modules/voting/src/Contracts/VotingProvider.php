<?php

namespace KaevCMS\Modules\Voting\Contracts;

use KaevCMS\Modules\Voting\Data\VoteInvitation;

interface VotingProvider
{
    public function code(): string;

    public function name(): string;

    /** Read-only connection check; returns a safe, fixed result code. */
    public function check(string $projectId, #[\SensitiveParameter] string $key): string;

    public function invite(string $projectId, #[\SensitiveParameter] string $key, string $requestId, string $playerRef): VoteInvitation;
}
