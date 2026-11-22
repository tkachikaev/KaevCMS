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

    /** @var array<string, Closure(): bool> */
    private array $accountVisibilityResolvers = [];

    /** @var array<string, true> Modules whose account link stays out of the sidebar. */
    private array $outsideSidebar = [];

    /** @var array<string, true> Modules whose account link opens a page with its own layout. */
    private array $standalonePages = [];

    /** @var array<string, array<string, mixed>> */
    private array $walletLinks = [];

    /** @var array<string, Closure(): bool> */
    private array $walletVisibilityResolvers = [];

    /** @var array<string, array<string, mixed>> */
    private array $adminLinks = [];

    /** @var array<string, Closure(Admin): int> */
    private array $adminBadgeResolvers = [];

    /** @var array<string, array<string, mixed>> Module pages listed under Admin → Themes. */
    private array $appearanceLinks = [];

    /**
     * $inSidebar = false keeps the entry for other account surfaces (the wallet's top-up
     * button and menu) without a duplicate item in the account sidebar.
     *
     * $standalone = true marks a page with its own layout and styles (the forum): account
     * themes open it with a full page load, because a Livewire visit would show it for a
     * moment without its stylesheet.
     */
    public function registerAccountLink(
        string $moduleId,
        string $routeName,
        string $labelKey,
        string $descriptionKey,
        int $sortOrder = 100,
        bool $inSidebar = true,
        ?Closure $visibleWhen = null,
        bool $standalone = false,
    ): void {
        $this->accountLinks[$moduleId] = $this->validatedLink(
            moduleId: $moduleId,
            routeName: $routeName,
            labelKey: $labelKey,
            descriptionKey: $descriptionKey,
            sortOrder: $sortOrder,
            routePrefix: 'modules.'.$moduleId.'.',
        );
        if ($inSidebar) {
            unset($this->outsideSidebar[$moduleId]);
        } else {
            $this->outsideSidebar[$moduleId] = true;
        }
        if ($standalone) {
            $this->standalonePages[$moduleId] = true;
        } else {
            unset($this->standalonePages[$moduleId]);
        }
        if ($visibleWhen === null) {
            unset($this->accountVisibilityResolvers[$moduleId]);
        } else {
            $this->accountVisibilityResolvers[$moduleId] = $visibleWhen;
        }
    }

    /** @param Closure(): bool $visibleWhen */
    public function registerWalletLink(
        string $moduleId,
        string $routeName,
        string $labelKey,
        string $descriptionKey,
        Closure $visibleWhen,
        int $sortOrder = 100,
    ): void {
        $key = $moduleId.':'.$routeName;
        $this->walletLinks[$key] = $this->validatedLink(
            $moduleId, $routeName, $labelKey, $descriptionKey, $sortOrder, 'modules.'.$moduleId.'.',
        );
        $this->walletVisibilityResolvers[$key] = $visibleWhen;
    }

    /** @return list<array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int}> */
    public function walletLinks(): array
    {
        return array_values(array_filter($this->availableLinks($this->walletLinks), function (array $link): bool {
            try {
                return ($this->walletVisibilityResolvers[$link['module_id'].':'.$link['route']])();
            } catch (Throwable) {
                return false;
            }
        }));
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

    /**
     * A module with its own look (the forum) lists its theme page next to the site
     * and account themes. The link disappears with the module.
     */
    public function registerAppearanceLink(
        string $moduleId,
        string $routeName,
        string $labelKey,
        string $descriptionKey,
        int $sortOrder = 100,
    ): void {
        $this->appearanceLinks[$moduleId] = $this->validatedLink(
            moduleId: $moduleId,
            routeName: $routeName,
            labelKey: $labelKey,
            descriptionKey: $descriptionKey,
            sortOrder: $sortOrder,
            routePrefix: 'admin.module-pages.'.$moduleId.'.',
        );
    }

    /** @return list<array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int}> */
    public function availableAppearanceLinks(Admin $admin, ModuleAdminAccessRegistry $access): array
    {
        return array_values(array_filter(
            $this->availableLinks($this->appearanceLinks),
            static function (array $link) use ($access, $admin): bool {
                $routeName = (string) $link['route'];

                return $access->isRegistered($routeName)
                    ? $access->canViewRoute($admin, $routeName)
                    : $admin->hasPermission(AdminPermission::AppearanceView);
            },
        ));
    }

    /** @return list<array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int,standalone:bool}> */
    public function accountLinks(): array
    {
        $links = array_map(function (array $link): array {
            $link['standalone'] = isset($this->standalonePages[$link['module_id']]);

            return $link;
        }, $this->availableLinks($this->accountLinks));

        return array_values(array_filter($links, function (array $link): bool {
            $resolver = $this->accountVisibilityResolvers[$link['module_id']] ?? null;
            if ($resolver === null) {
                return true;
            }
            try {
                return $resolver();
            } catch (Throwable) {
                return false;
            }
        }));
    }

    /** @return list<array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int,standalone:bool}> */
    public function sidebarAccountLinks(): array
    {
        return array_values(array_filter(
            $this->accountLinks(),
            fn (array $link): bool => ! isset($this->outsideSidebar[$link['module_id']]),
        ));
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
