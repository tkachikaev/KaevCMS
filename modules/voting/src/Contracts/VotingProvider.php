<?php

namespace KaevCMS\Modules\Voting\Contracts;

interface VotingProvider
{
    public function code(): string;

    public function name(): string;

    /** Read-only connection check; returns a safe, fixed result code. */
    public function check(string $projectId, #[\SensitiveParameter] string $key): string;
}
