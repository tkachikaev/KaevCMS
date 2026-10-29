<?php

namespace App\Services\Auth\Social;

use InvalidArgumentException;

final class SocialProviders
{
    public const GOOGLE = 'google';

    public const YANDEX = 'yandex';

    public const VK = 'vk';

    public const TELEGRAM = 'telegram';

    public const DISCORD = 'discord';

    public const FACEBOOK = 'facebook';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::GOOGLE, self::YANDEX, self::VK, self::TELEGRAM, self::DISCORD, self::FACEBOOK];
    }

    public static function assertAllowed(string $provider): void
    {
        if (! in_array($provider, self::all(), true)) {
            throw new InvalidArgumentException('Unsupported social identity provider.');
        }
    }
}
