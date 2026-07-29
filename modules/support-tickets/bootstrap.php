<?php

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Support\Modules\ModuleAdminAccessLevel;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Contracts\Foundation\Application;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings;

return static function (Application $app, ModuleContext $module): void {
    $navigation = $app->make(ModuleNavigationRegistry::class);
    $navigation->registerAccountLink(
        moduleId: $module->id,
        routeName: 'modules.support-tickets.index',
        labelKey: 'module-support-tickets::messages.navigation_label',
        descriptionKey: 'module-support-tickets::messages.navigation_description',
        sortOrder: 60,
    );
    $navigation->registerAdminLink(
        moduleId: $module->id,
        routeName: 'admin.module-pages.support-tickets.index',
        labelKey: 'module-support-tickets::messages.admin_navigation_label',
        descriptionKey: 'module-support-tickets::messages.admin_navigation_description',
        sortOrder: 60,
    );

    $access = $app->make(ModuleAdminAccessRegistry::class);
    $editorAccess = static function (Admin $admin) use ($app): ModuleAdminAccessLevel {
        if (
            $admin->role === AdminRole::Editor
            && $app->make(SupportTicketSettings::class)->editorsCanManage()
        ) {
            return ModuleAdminAccessLevel::Manage;
        }

        return ModuleAdminAccessLevel::Denied;
    };

    $access->registerExact(
        moduleId: $module->id,
        routeName: 'admin.module-pages.support-tickets.settings',
        viewRoles: [],
        manageRoles: [AdminRole::Owner],
    );
    $access->registerExact(
        moduleId: $module->id,
        routeName: 'admin.module-pages.support-tickets.settings.update',
        viewRoles: [],
        manageRoles: [AdminRole::Owner],
    );
    $access->registerPrefix(
        moduleId: $module->id,
        routePrefix: 'admin.module-pages.support-tickets.',
        viewRoles: [AdminRole::Auditor],
        manageRoles: [AdminRole::Owner, AdminRole::Administrator],
        additionalAccess: $editorAccess,
    );
};
