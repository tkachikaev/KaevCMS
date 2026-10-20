<?php

namespace App\Services\Servers;

use Illuminate\Support\Facades\Cache;

/**
 * Server-wide circuit breaker for player-facing reads from external game and login
 * databases. After one failure every request skips that server for a short cooldown
 * instead of each player and each game account waiting for the same timeout.
 */
final class ExternalServerCooldown
{
    public const GAME_SERVER = 'game-server';

    public const LOGIN_SERVER = 'login-server';

    public function isCoolingDown(string $scope, int $serverId): bool
    {
        return Cache::has($this->key($scope, $serverId));
    }

    public function trip(string $scope, int $serverId): void
    {
        Cache::put($this->key($scope, $serverId), true, now()->addSeconds($this->seconds()));
    }

    private function key(string $scope, int $serverId): string
    {
        return 'external-server-unavailable:'.$scope.':'.$serverId;
    }

    private function seconds(): int
    {
        return max(5, min(600, (int) config('cms.external_database.unavailable_cooldown_seconds', 30)));
    }
}
