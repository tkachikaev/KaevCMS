<?php

namespace App\Support\GameAccounts;

final readonly class PreparedGameAccount
{
    public function __construct(
        public string $login,
        public string $credential,
        public string $email,
    ) {}
}
