<?php

namespace App\Support\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use Closure;
use InvalidArgumentException;

final class ModuleAdminAccessRegistry
{
    /** @var list<ModuleAdminAccessRule> */
    private array $rules = [];

    /**
     * @param  list<AdminRole>  $viewRoles
     * @param  list<AdminRole>  $manageRoles
     * @param  (Closure(Admin): ModuleAdminAccessLevel)|null  $additionalAccess
     */
    public function registerExact(
        string $moduleId,
        string $routeName,
        array $viewRoles,
        array $manageRoles,
        ?Closure $additionalAccess = null,
    ): void {
        $this->register($moduleId, $routeName, $viewRoles, $manageRoles, false, $additionalAccess);
    }

    /**
     * @param  list<AdminRole>  $viewRoles
     * @param  list<AdminRole>  $manageRoles
     * @param  (Closure(Admin): ModuleAdminAccessLevel)|null  $additionalAccess
     */
    public function registerPrefix(
        string $moduleId,
        string $routePrefix,
        array $viewRoles,
        array $manageRoles,
        ?Closure $additionalAccess = null,
    ): void {
        $this->register($moduleId, $routePrefix, $viewRoles, $manageRoles, true, $additionalAccess);
    }

    public function decision(string $routeName, string $method, Admin $admin): ?ModuleAdminAccessDecision
    {
        return $this->resolve($routeName)?->decision($admin, $method);
    }

    public function isRegistered(string $routeName): bool
    {
        return $this->resolve($routeName) !== null;
    }

    public function canViewRoute(Admin $admin, string $routeName): bool
    {
        return $this->decision($routeName, 'GET', $admin)?->allowed === true;
    }

    /** @return list<ModuleAdminAccessRule> */
    public function rules(): array
    {
        return $this->rules;
    }

    private function resolve(string $routeName): ?ModuleAdminAccessRule
    {
        $matches = array_values(array_filter(
            $this->rules,
            static fn (ModuleAdminAccessRule $rule): bool => $rule->matches($routeName),
        ));

        usort($matches, static function (ModuleAdminAccessRule $left, ModuleAdminAccessRule $right): int {
            if ($left->prefix !== $right->prefix) {
                return $left->prefix ? 1 : -1;
            }

            return strlen($right->pattern) <=> strlen($left->pattern);
        });

        return $matches[0] ?? null;
    }

    /**
     * @param  list<AdminRole>  $viewRoles
     * @param  list<AdminRole>  $manageRoles
     * @param  (Closure(Admin): ModuleAdminAccessLevel)|null  $additionalAccess
     */
    private function register(
        string $moduleId,
        string $pattern,
        array $viewRoles,
        array $manageRoles,
        bool $prefix,
        ?Closure $additionalAccess,
    ): void {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,99}\z/', $moduleId) !== 1) {
            throw new InvalidArgumentException('Module access identifier is invalid.');
        }

        $requiredPrefix = 'admin.module-pages.'.$moduleId.'.';
        if (! str_starts_with($pattern, $requiredPrefix)) {
            throw new InvalidArgumentException('Module access route is outside its module namespace.');
        }

        foreach (array_merge($viewRoles, $manageRoles) as $role) {
            if (! $role instanceof AdminRole) {
                throw new InvalidArgumentException('Module access roles must use AdminRole values.');
            }
        }

        $this->rules[] = new ModuleAdminAccessRule(
            moduleId: $moduleId,
            pattern: $pattern,
            viewRoles: array_values($viewRoles),
            manageRoles: array_values($manageRoles),
            prefix: $prefix,
            additionalAccess: $additionalAccess,
        );
    }
}
