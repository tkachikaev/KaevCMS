<?php

namespace App\Services\GameAccounts;

final class MobiusPasswordEncoder
{
    public function encode(string $password): string
    {
        return base64_encode(sha1($password, true));
    }
}
