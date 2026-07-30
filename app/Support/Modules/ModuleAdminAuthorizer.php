<?php

namespace App\Support\Modules;

use App\Models\Admin;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ModuleAdminAuthorizer
{
    public function __construct(
        private ModuleAdminAccessRegistry $registry,
    ) {}

    public function decision(string $routeName, string $method, Admin $admin): ?ModuleAdminAccessDecision
    {
        return $this->registry->decision($routeName, $method, $admin);
    }

    public function can(string $routeName, string $method, Admin $admin): bool
    {
        return $this->decision($routeName, $method, $admin)?->allowed === true;
    }

    /** @throws AuthorizationException */
    public function authorize(string $routeName, string $method, Admin $admin): ModuleAdminAccessDecision
    {
        $decision = $this->decision($routeName, $method, $admin);
        if ($decision === null || ! $decision->allowed) {
            throw new AuthorizationException;
        }

        return $decision;
    }
}
