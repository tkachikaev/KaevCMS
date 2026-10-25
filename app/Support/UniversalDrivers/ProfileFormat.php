<?php

namespace App\Support\UniversalDrivers;

/**
 * Strict, data-only version of the driver-profile format.
 * This never opens a game database and must not execute definitions as code.
 */
final class ProfileFormat
{
    public const CURRENT_VERSION = 1;

    public const MAX_BYTES = 262144;

    /** @var list<string> */
    public const LOGIN_CAPABILITIES = ['account_lookup', 'account_creation', 'password_change', 'account_status'];

    /** @var list<string> */
    public const GAME_CAPABILITIES = ['characters', 'level', 'pvp', 'pk', 'play_time', 'heroes', 'castles', 'character_rescue'];

    /** @var list<string> */
    public const PASSWORD_SCHEMES = ['sha1_base64', 'bcrypt_2a', 'sha1_hex', 'md5_hex', 'sha256_hex', 'whirlpool_hex'];

    /**
     * Unsalted hex digests: scheme => [hash algorithm, digest length in characters]. The CMS always
     * writes lowercase hex, so only lowercase is accepted as a prepared credential.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    public const HEX_PASSWORD_SCHEMES = [
        'sha1_hex' => ['sha1', 40],
        'md5_hex' => ['md5', 32],
        'sha256_hex' => ['sha256', 64],
        'whirlpool_hex' => ['whirlpool', 128],
    ];

    /** @var list<string> */
    public const FIELD_NAMES = [
        'id', 'name', 'level', 'experience', 'class_id', 'race', 'gender', 'title', 'online',
        'last_seen_at_ms', 'play_time_seconds', 'pvp_kills', 'pk_kills', 'reputation',
        'noble', 'clan_id', 'clan_name', 'hero', 'created_at', 'x', 'y', 'z',
    ];

    /** @var list<string> */
    public const PROFILE_DATABASES = ['mysql'];
}
