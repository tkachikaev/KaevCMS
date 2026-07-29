<?php

namespace App\Support\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use Closure;

final readonly class ModuleAdminAccessRule
{
    /**
     * @param  list<AdminRole>  $viewRoles
     * @param  list<AdminRole>  $manageRoles
     * @param  (Closure(Admin): ModuleAdminAccessLevel)|null  $additionalAccess
     */
    public function __construct(
        public string $moduleId,
        public string $pattern,
        public array $viewRoles,
        public array $manageRoles,
        public bool $prefix = false,
        public ?Closure $additionalAccess = null,
    ) {}

    public function matches(string $routeName): bool
    {
        return $this->prefix
            ? str_starts_with($routeName, $this->pattern)
            : $routeName === $this->pattern;
    }

    public function level(Admin $admin): ModuleAdminAccessLevel
    {
        if (in_array($admin->role, $this->manageRoles, true)) {
            return ModuleAdminAccessLevel::Manage;
        }

        if (in_array($admin->role, $this->viewRoles, true)) {
            return ModuleAdminAccessLevel::Read;
        }

        return $this->additionalAccess instanceof Closure
            ? ($this->additionalAccess)($admin)
            : ModuleAdminAccessLevel::Denied;
    }

    public function decision(Admin $admin, string $method): ModuleAdminAccessDecision
    {
        $level = $this->level($admin);
        $isRead = in_array(strtoupper($method), ['GET', 'HEAD'], true);

        return match ($level) {
            ModuleAdminAccessLevel::Manage => new ModuleAdminAccessDecision(true, false),
            ModuleAdminAccessLevel::Read => new ModuleAdminAccessDecision($isRead, true),
            ModuleAdminAccessLevel::Denied => new ModuleAdminAccessDecision(false, false),
        };
    }
}
