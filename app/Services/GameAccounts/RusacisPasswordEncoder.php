<?php

namespace App\Services\GameAccounts;

use RuntimeException;

/**
 * RUSaCis accounts use BCrypt $2a$ hashes; PHP emits $2y$ for the same
 * modern BCrypt format. Retag hashes for Java LoginServer compatibility.
 * Real server login must be verified against the target RUSaCis build.
 */
final class RusacisPasswordEncoder
{
    public function encode(string $password): string
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        if (! is_string($hash) || ! str_starts_with($hash, '$2y$') || strlen($hash) !== 60) {
            throw new RuntimeException('Unable to prepare a RUSaCis BCrypt password hash.');
        }

        return '$2a$'.substr($hash, 4);
    }
}
