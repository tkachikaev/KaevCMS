<?php

namespace App\Support;

use Illuminate\Support\Str;

final class PasswordHashing
{
    public const BCRYPT_MAX_BYTES = 72;

    public static function effectiveDriver(): string
    {
        return (string) config('hashing.driver', 'bcrypt');
    }

    public static function requestedDriver(): string
    {
        return (string) config('hashing.requested_driver', self::effectiveDriver());
    }

    public static function label(): string
    {
        return match (self::effectiveDriver()) {
            'argon2id' => 'Argon2id',
            'argon' => 'Argon2i',
            'bcrypt' => 'bcrypt',
            default => Str::headline(self::effectiveDriver()),
        };
    }

    public static function supports(string $algorithm): bool
    {
        if (! function_exists('password_algos')) {
            return in_array(strtolower($algorithm), ['2y', 'bcrypt'], true);
        }

        $normalized = match (strtolower($algorithm)) {
            'bcrypt' => '2y',
            'argon' => 'argon2i',
            default => strtolower($algorithm),
        };

        return in_array($normalized, password_algos(), true);
    }

    public static function argon2idSupported(): bool
    {
        return self::supports('argon2id');
    }

    public static function usesBcrypt(): bool
    {
        return self::effectiveDriver() === 'bcrypt';
    }

    public static function accepts(string $password): bool
    {
        return ! self::usesBcrypt() || strlen($password) <= self::BCRYPT_MAX_BYTES;
    }
}
