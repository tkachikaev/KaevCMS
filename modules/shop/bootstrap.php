<?php

use App\Auth\AdminRole;
use App\Models\GameServer;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleGameServerDependencyRegistry;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Contracts\Foundation\Application;
use KaevCMS\Modules\Shop\Models\ShopTab;

return static function (Application $app, ModuleContext $module): void {
    // Catalog management is admin-only. There is still no storefront or checkout.
    $app->make(ModuleGameServerDependencyRegistry::class)->register(
        $module->id,
        static fn (GameServer $server): bool => ShopTab::query()->where('game_server_id', $server->id)->exists(),
    );

    $app->make(ModuleNavigationRegistry::class)->registerAdminLink(
        moduleId: $module->id,
        routeName: 'admin.module-pages.shop.index',
        labelKey: 'module-shop::messages.admin_navigation_label',
        descriptionKey: 'module-shop::messages.admin_navigation_description',
        sortOrder: 80,
    );

    $app->make(ModuleAdminAccessRegistry::class)->registerPrefix(
        moduleId: $module->id,
        routePrefix: 'admin.module-pages.shop.',
        viewRoles: [AdminRole::Auditor],
        manageRoles: [AdminRole::Owner, AdminRole::Administrator],
    );
};
