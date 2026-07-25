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
            ->table('module_promo_codes')
            ->where('game_server_id', $server->id)
            ->whereNull('deleted_at')
            ->exists()
            || $server->getConnection()
                ->table('module_promo_code_activations')
                ->where('game_server_id', $server->id)
                ->exists(),
    );

    $navigation = $app->make(ModuleNavigationRegistry::class);
    $navigation->registerAccountLink(
        moduleId: $module->id,
        routeName: 'modules.promo-codes.index',
        labelKey: 'module-promo-codes::messages.navigation_label',
        descriptionKey: 'module-promo-codes::messages.navigation_description',
        sortOrder: 30,
    );
    $navigation->registerAdminLink(
        moduleId: $module->id,
        routeName: 'admin.module-pages.promo-codes.index',
        labelKey: 'module-promo-codes::messages.admin_navigation_label',
        descriptionKey: 'module-promo-codes::messages.admin_navigation_description',
        sortOrder: 30,
    );
};
