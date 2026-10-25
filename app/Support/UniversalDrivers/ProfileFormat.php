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
    public const PASSWORD_SCHEMES = ['sha1_base64', 'bcrypt_2a'];

    /** @var list<string> */
    public const FIELD_NAMES = [
        'id', 'name', 'level', 'experience', 'class_id', 'race', 'gender', 'title', 'online',
        'last_seen_at_ms', 'play_time_seconds', 'pvp_kills', 'pk_kills', 'reputation',
        'noble', 'clan_id', 'clan_name', 'hero', 'created_at', 'x', 'y', 'z',
    ];

    /** @var list<string> */
    public const PROFILE_DATABASES = ['mysql'];
}
