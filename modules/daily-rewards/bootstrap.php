<?php

use App\Models\GameServer;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleGameServerDependencyRegistry;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Contracts\Foundation\Application;

return static function (Application $app, ModuleContext $module): void {
    $dependencies = $app->make(ModuleGameServerDependencyRegistry::class);
    $dependencies->register(
        $module->id,
        static fn (GameServer $server): bool => $server->getConnection()
            ->table('module_daily_reward_calendars')
            ->where('game_server_id', $server->id)
            ->exists()
            || $server->getConnection()
                ->table('module_daily_reward_claims')
                ->where('game_server_id', $server->id)
                ->exists(),
    );

    $navigation = $app->make(ModuleNavigationRegistry::class);
    $navigation->registerAccountLink(
        moduleId: $module->id,
        routeName: 'modules.daily-rewards.index',
        labelKey: 'module-daily-rewards::messages.navigation_label',
        descriptionKey: 'module-daily-rewards::messages.navigation_description',
        sortOrder: 40,
    );
    $navigation->registerAdminLink(
        moduleId: $module->id,
        routeName: 'admin.module-pages.daily-rewards.index',
        labelKey: 'module-daily-rewards::messages.admin_navigation_label',
        descriptionKey: 'module-daily-rewards::messages.admin_navigation_description',
        sortOrder: 40,
    );
};
