<?php

use App\Auth\AdminRole;
use App\Models\GameServer;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleGameServerDependencyRegistry;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Contracts\Foundation\Application;

return static function (Application $app, ModuleContext $module): void {
    // Archive rows must never disappear when an administrator deletes a GameServer.
    $app->make(ModuleGameServerDependencyRegistry::class)->register(
        $module->id,
        static fn (GameServer $server): bool => $server->getConnection()
            ->table('module_referral_snapshots')
            ->where('game_server_id', $server->id)
            ->exists()
            || $server->getConnection()->table('module_referral_entitlements')
                ->where('game_server_id', $server->id)->exists()
            || $server->getConnection()->table('module_referral_progress')
                ->where('game_server_id', $server->id)->exists()
            || $server->getConnection()->table('module_referral_rules')
                ->where('game_server_id', $server->id)->exists()
            || $server->getConnection()->table('module_referral_server_settings')
                ->where('game_server_id', $server->id)->exists(),
    );

    // The player link is registered only when the player experience exists (iteration 6).
    // Until then enabling this foundation cannot lead players to an unfinished page.
    $app->make(ModuleNavigationRegistry::class)->registerAdminLink(
        moduleId: $module->id,
        routeName: 'admin.module-pages.referrals.index',
        labelKey: 'module-referrals::messages.admin_navigation_label',
        descriptionKey: 'module-referrals::messages.admin_navigation_description',
        sortOrder: 70,
    );

    $app->make(ModuleAdminAccessRegistry::class)->registerExact(
        moduleId: $module->id,
        routeName: 'admin.module-pages.referrals.index',
        viewRoles: [AdminRole::Auditor],
        manageRoles: [AdminRole::Owner, AdminRole::Administrator],
    );
};
