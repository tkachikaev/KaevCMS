<?php

use App\Auth\AdminRole;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Contracts\Foundation\Application;
use KaevCMS\Modules\Voting\Services\VotingAvailability;

return static function (Application $app, ModuleContext $module): void {
    $app->make(ModuleNavigationRegistry::class)->registerAdminLink(
        moduleId: $module->id,
        routeName: 'admin.module-pages.voting.index',
        labelKey: 'module-voting::messages.title',
        descriptionKey: 'module-voting::messages.description',
        sortOrder: 80,
    );

    $app->make(ModuleNavigationRegistry::class)->registerAccountLink(
        moduleId: $module->id,
        routeName: 'modules.voting.index',
        labelKey: 'module-voting::messages.title',
        descriptionKey: 'module-voting::messages.account_description',
        sortOrder: 80,
        visibleWhen: static fn (): bool => $app->make(VotingAvailability::class)->visible(),
    );

    $app->make(ModuleAdminAccessRegistry::class)->registerPrefix(
        moduleId: $module->id,
        routePrefix: 'admin.module-pages.voting.',
        viewRoles: [AdminRole::Auditor],
        manageRoles: [AdminRole::Owner, AdminRole::Administrator],
    );
};
