<?php

use App\Auth\AdminRole;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Contracts\Foundation\Application;

return static function (Application $app, ModuleContext $module): void {
    // Phase 1 has no storefront or checkout routes: enabling the module cannot sell items.
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
