<?php

use App\Auth\AdminRole;
use App\Contracts\WalletTopUpHistory;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Contracts\Foundation\Application;
use KaevCMS\Modules\Donations\Services\DonationGameExchange;
use KaevCMS\Modules\Donations\Services\DonationPlayerHistory;
use KaevCMS\Modules\Donations\Services\DonationSettings;

return static function (Application $app, ModuleContext $module): void {
    $app->bind(WalletTopUpHistory::class, DonationPlayerHistory::class);
    $navigation = $app->make(ModuleNavigationRegistry::class);
    $navigation->registerAdminLink(
        moduleId: $module->id,
        routeName: 'admin.module-pages.donations.index',
        labelKey: 'module-donations::messages.admin_navigation_label',
        descriptionKey: 'module-donations::messages.admin_navigation_description',
        sortOrder: 70,
    );

    // The registry has one account entry per module. Prefer coin selection while
    // enabled; history stays reachable from that page and becomes the entry when closed.
    // If the table is unavailable/corrupt, visibility fails closed.
    if ($app->make(DonationSettings::class)->isVisibleToPlayers()) {
        $navigation->registerAccountLink(
            moduleId: $module->id,
            routeName: 'modules.donations.index',
            labelKey: 'module-donations::messages.navigation_label',
            descriptionKey: 'module-donations::messages.navigation_description',
            sortOrder: 70,
        );
    } elseif ($app->make(DonationGameExchange::class)->available() !== []) {
        $navigation->registerAccountLink(
            moduleId: $module->id,
            routeName: 'modules.donations.exchange.index',
            labelKey: 'module-donations::messages.exchange_title',
            descriptionKey: 'module-donations::messages.exchange_account_help',
            sortOrder: 70,
        );
    } else {
        // History must remain available even when the owner stops accepting new drafts.
        $navigation->registerAccountLink(
            moduleId: $module->id,
            routeName: 'modules.donations.orders.index',
            labelKey: 'module-donations::messages.orders_title',
            descriptionKey: 'module-donations::messages.orders_description',
            sortOrder: 71,
        );
    }

    $access = $app->make(ModuleAdminAccessRegistry::class);
    $access->registerExact(
        moduleId: $module->id,
        routeName: 'admin.module-pages.donations.index',
        viewRoles: [AdminRole::Auditor],
        manageRoles: [AdminRole::Owner, AdminRole::Administrator],
    );
    foreach (['orders.index', 'orders.show', 'providers.index', 'providers.show', 'exchange.index'] as $route) {
        $access->registerExact(
            moduleId: $module->id,
            routeName: 'admin.module-pages.donations.'.$route,
            viewRoles: [AdminRole::Auditor],
            manageRoles: [AdminRole::Owner, AdminRole::Administrator],
        );
    }
    foreach (['workspace.update', 'settings.update', 'limits.update', 'rates.store', 'providers.update', 'providers.activation.update', 'orders.reviews.store', 'exchange.update'] as $route) {
        $access->registerExact(
            moduleId: $module->id,
            routeName: 'admin.module-pages.donations.'.$route,
            viewRoles: [],
            manageRoles: [AdminRole::Owner, AdminRole::Administrator],
        );
    }
};
