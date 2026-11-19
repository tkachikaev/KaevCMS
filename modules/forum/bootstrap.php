<?php

use App\Auth\AdminRole;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Contracts\Foundation\Application;

return static function (Application $app, ModuleContext $module): void {
    $navigation = $app->make(ModuleNavigationRegistry::class);
    $navigation->registerAccountLink(
        moduleId: $module->id,
        routeName: 'modules.forum.index',
        labelKey: 'module-forum::messages.navigation',
        descriptionKey: 'module-forum::messages.navigation_description',
        sortOrder: 85,
    );
    $navigation->registerAdminLink(
        moduleId: $module->id,
        routeName: 'admin.module-pages.forum.settings',
        labelKey: 'module-forum::messages.settings',
        descriptionKey: 'module-forum::messages.settings_description',
        sortOrder: 85,
    );

    $app->make(ModuleAdminAccessRegistry::class)->registerExact(
        moduleId: $module->id,
        routeName: 'admin.module-pages.forum.settings',
        viewRoles: [],
        manageRoles: [AdminRole::Owner],
    );
};
