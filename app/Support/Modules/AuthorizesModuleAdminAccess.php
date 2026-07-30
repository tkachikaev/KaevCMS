<?php

namespace App\Support\Modules;

use App\Models\Admin;
use Illuminate\Auth\Access\AuthorizationException;

trait AuthorizesModuleAdminAccess
{
    /** @throws AuthorizationException */
    protected function moduleAdminActor(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            throw new AuthorizationException;
        }

        return $admin;
    }

    /** @throws AuthorizationException */
    protected function authorizeModuleAdminRoute(
        string $routeName,
        string $method,
        ?Admin $admin = null,
    ): ModuleAdminAccessDecision {
        return app(ModuleAdminAuthorizer::class)->authorize(
            $routeName,
            $method,
            $admin ?? $this->moduleAdminActor(),
        );
    }

    protected function canUseModuleAdminRoute(
        string $routeName,
        string $method,
        ?Admin $admin = null,
    ): bool {
        return app(ModuleAdminAuthorizer::class)->can(
            $routeName,
            $method,
            $admin ?? $this->moduleAdminActor(),
        );
    }
}
