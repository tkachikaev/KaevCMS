<?php

namespace App\Support\Modules;

use App\Auth\AdminPermission;
use App\Models\Admin;
use Closure;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Throwable;

final class ModuleNavigationRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $accountLinks = [];

    /** @var array<string, array<string, mixed>> */
    private array $adminLinks = [];

    /** @var array<string, Closure(Admin): int> */
    private array $adminBadgeResolvers = [];

    public function registerAccountLink(
        string $moduleId,
        string $routeName,
        string $labelKey,
        string $descriptionKey,
        int $sortOrder = 100,
    ): void {
        $this->accountLinks[$moduleId] = $this->validatedLink(
            moduleId: $moduleId,
            routeName: $routeName,
            labelKey: $labelKey,
            descriptionKey: $descriptionKey,
            sortOrder: $sortOrder,
            routePrefix: 'modules.'.$moduleId.'.',
        );
    }

    /** @param Closure(Admin): int|null $badgeResolver */
    public function registerAdminLink(
        string $moduleId,
        string $routeName,
        string $labelKey,
        string $descriptionKey,
        int $sortOrder = 100,
        ?Closure $badgeResolver = null,
    ): void {
        $this->adminLinks[$moduleId] = $this->validatedLink(
            moduleId: $moduleId,
            routeName: $routeName,
            labelKey: $labelKey,
            descriptionKey: $descriptionKey,
            sortOrder: $sortOrder,
            routePrefix: 'admin.module-pages.'.$moduleId.'.',
        );

        if ($badgeResolver === null) {
            unset($this->adminBadgeResolvers[$moduleId]);
        } else {
            $this->adminBadgeResolvers[$moduleId] = $badgeResolver;
        }
    }

    /** @return list<array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int}> */
    public function accountLinks(): array
    {
        return $this->availableLinks($this->accountLinks);
    }

    /** @return list<array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int}> */
    public function adminLinks(): array
    {
        return $this->availableLinks($this->adminLinks);
    }

    /** @return list<array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int,badge:int,badge_enabled:bool}> */
    public function availableAdminLinks(Admin $admin, ModuleAdminAccessRegistry $access): array
    {
        $links = array_values(array_filter(
            $this->adminLinks(),
            static function (array $link) use ($access, $admin): bool {
                $routeName = (string) $link['route'];

                return $access->isRegistered($routeName)
                    ? $access->canViewRoute($admin, $routeName)
                    : $admin->hasPermission(AdminPermission::ModulesView);
            },
        ));

        return array_map(function (array $link) use ($admin): array {
            $moduleId = (string) $link['module_id'];
            $link['badge_enabled'] = isset($this->adminBadgeResolvers[$moduleId]);
            $link['badge'] = $this->adminBadgeFor($moduleId, $admin);

            /** @var array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int,badge:int,badge_enabled:bool} $link */
            return $link;
        }, $links);
    }

    /**
     * @param  array<string, array<string, mixed>>  $links
     * @return list<array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int}>
     */
    private function availableLinks(array $links): array
    {
        $resolved = array_values(array_filter(
            $links,
            static fn (array $link): bool => Route::has((string) $link['route']),
        ));

        usort($resolved, static function (array $left, array $right): int {
            $order = ((int) $left['sort_order']) <=> ((int) $right['sort_order']);

            return $order !== 0
                ? $order
                : strcasecmp((string) $left['module_id'], (string) $right['module_id']);
        });

        /** @var list<array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int}> $resolved */
        return $resolved;
    }

    public function adminBadgeFor(string $moduleId, Admin $admin): int
    {
        $resolver = $this->adminBadgeResolvers[$moduleId] ?? null;
        if (! $resolver instanceof Closure) {
            return 0;
        }

        try {
            return min(999999, max(0, (int) $resolver($admin)));
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
    }

    /** @return array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int} */
    private function validatedLink(
        string $moduleId,
        string $routeName,
        string $labelKey,
        string $descriptionKey,
        int $sortOrder,
        string $routePrefix,
    ): array {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,99}\z/', $moduleId) !== 1) {
            throw new InvalidArgumentException('Module navigation identifier is invalid.');
        }

        if (! str_starts_with($routeName, $routePrefix)) {
            throw new InvalidArgumentException('Module navigation route is outside its module namespace.');
        }

        if (trim($labelKey) === '' || trim($descriptionKey) === '') {
            throw new InvalidArgumentException('Module navigation translation keys are required.');
        }

        return [
            'module_id' => $moduleId,
            'route' => $routeName,
            'label_key' => trim($labelKey),
            'description_key' => trim($descriptionKey),
            'sort_order' => max(0, min(100000, $sortOrder)),
        ];
    }
}
