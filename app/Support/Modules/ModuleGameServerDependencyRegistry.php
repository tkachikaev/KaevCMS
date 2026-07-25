<?php

namespace App\Support\Modules;

use App\Models\GameServer;
use Closure;
use InvalidArgumentException;
use Throwable;

final class ModuleGameServerDependencyRegistry
{
    /** @var array<string, Closure(GameServer): bool> */
    private array $guards = [];

    /** @param Closure(GameServer): bool $guard */
    public function register(string $moduleId, Closure $guard): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,99}\z/', $moduleId) !== 1) {
            throw new InvalidArgumentException('Module dependency guard identifier is invalid.');
        }

        $this->guards[$moduleId] = $guard;
    }

    public function blocksDeletion(GameServer $server): bool
    {
        foreach ($this->guards as $guard) {
            try {
                if ($guard($server)) {
                    return true;
                }
            } catch (Throwable) {
                // A failed module dependency check must never allow destructive deletion.
                return true;
            }
        }

        return false;
    }
}
