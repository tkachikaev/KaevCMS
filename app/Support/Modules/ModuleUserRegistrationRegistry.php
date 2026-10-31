<?php

namespace App\Support\Modules;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/** Extension point invoked only when the public registration creates a new CMS user. */
final class ModuleUserRegistrationRegistry
{
    /** @var array<string, Closure(User, Request): void> */
    private array $handlers = [];

    /** @param Closure(User, Request): void $handler */
    public function register(string $moduleId, Closure $handler): void
    {
        $this->handlers[$moduleId] = $handler;
    }

    /** Must be called inside the same transaction that creates the user. */
    public function created(User $user, Request $request): void
    {
        foreach ($this->handlers as $handler) {
            $handler($user, $request);
        }
    }
}
