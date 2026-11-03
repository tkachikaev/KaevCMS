<?php

use App\Auth\AdminRole;
use App\Models\GameServer;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleGameServerDependencyRegistry;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopPurchase;
use KaevCMS\Modules\Shop\Models\ShopTab;

return static function (Application $app, ModuleContext $module): void {
    $app->make(ModuleGameServerDependencyRegistry::class)->register(
        $module->id,
        static fn (GameServer $server): bool => ShopTab::query()->where('game_server_id', $server->id)->exists()
            || ShopPurchase::query()->where('game_server_id', $server->id)->exists(),
    );

    $app->make(ModuleNavigationRegistry::class)->registerAccountLink(
        moduleId: $module->id,
        routeName: 'modules.shop.index',
        labelKey: 'module-shop::messages.account_navigation_label',
        descriptionKey: 'module-shop::messages.account_navigation_description',
        sortOrder: 65,
        // Players see the shop whenever a catalog is shown (it is browsable even
        // while sales are off) or they have purchase history to read.
        visibleWhen: static fn (): bool => ShopProduct::query()->visibleInCatalog()->exists()
            || (Auth::guard('web')->id() !== null
                && ShopPurchase::query()->where('user_id', Auth::guard('web')->id())->exists()),
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
