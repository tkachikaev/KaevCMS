<?php

namespace App\Services\Servers;

/**
 * The online count shown to website visitors: the real count plus the extra players an
 * administrator configured for the server. Administration screens keep the real count.
 */
final class PublicOnlineBoost
{
    /** A range addition changes every five minutes, not on every page view. */
    private const RANGE_WINDOW_SECONDS = 300;

    /** @param array{enabled:bool,mode:string,min:int,max:int,percent:int} $boost */
    public function apply(int $players, array $boost, int $serverId, ?int $timestamp = null): int
    {
        $players = max(0, $players);
        if (! $boost['enabled']) {
            return $players;
        }

        if ($boost['mode'] === 'percent') {
            return $players + (int) round($players * $boost['percent'] / 100);
        }

        $span = max(0, $boost['max'] - $boost['min']);
        $window = intdiv($timestamp ?? time(), self::RANGE_WINDOW_SECONDS);
        $offset = $span > 0 ? crc32($serverId.':'.$window) % ($span + 1) : 0;

        return $players + $boost['min'] + $offset;
    }
}
