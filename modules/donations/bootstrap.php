<?php

use App\Auth\AdminRole;
use App\Contracts\WalletTopUpHistory;
use App\Models\GameServer;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleGameServerDependencyRegistry;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Contracts\Foundation\Application;
use KaevCMS\Modules\Donations\Services\DonationGameExchange;
use KaevCMS\Modules\Donations\Services\DonationPlayerHistory;
use KaevCMS\Modules\Donations\Services\DonationSettings;

return static function (Application $app, ModuleContext $module): void {
    $app->make(ModuleGameServerDependencyRegistry::class)->register(
        $module->id,
        static fn (GameServer $server): bool => $server->getConnection()
            ->table('module_donation_game_exchanges')
            ->where('game_server_id', $server->id)
            ->exists(),
    );
    $app->bind(WalletTopUpHistory::class, DonationPlayerHistory::class);
    $navigation = $app->make(ModuleNavigationRegistry::class);
    $navigation->registerWalletLink(
        moduleId: $module->id,
        routeName: 'modules.donations.exchange.index',
        labelKey: 'module-donations::messages.exchange_wallet_action',
        descriptionKey: 'module-donations::messages.exchange_account_help',
        visibleWhen: fn (): bool => $app->make(DonationGameExchange::class)->available() !== [],
        sortOrder: 70,
    );
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
    // Top-up and exchange are opened from the wallet (its page and the header menu), so they
    // stay out of the account sidebar; only the history-only fallback is listed there.
    if ($app->make(DonationSettings::class)->isVisibleToPlayers()) {
        $navigation->registerAccountLink(
            moduleId: $module->id,
            routeName: 'modules.donations.index',
            labelKey: 'module-donations::messages.navigation_label',
            descriptionKey: 'module-donations::messages.navigation_description',
            sortOrder: 70,
            inSidebar: false,
        );
    } elseif ($app->make(DonationGameExchange::class)->available() !== []) {
        $navigation->registerAccountLink(
            moduleId: $module->id,
            routeName: 'modules.donations.exchange.index',
            labelKey: 'module-donations::messages.exchange_title',
            descriptionKey: 'module-donations::messages.exchange_account_help',
            sortOrder: 70,
            inSidebar: false,
        );
    } else {
        // History must remain available even when the owner stops accepting new drafts. It lives
        // in the wallet now (this route only redirects there), so a sidebar item would merely
        // duplicate "My wallet"; the wallet menu's History link keeps it one click away.
        $navigation->registerAccountLink(
            moduleId: $module->id,
            routeName: 'modules.donations.orders.index',
            labelKey: 'module-donations::messages.orders_title',
            descriptionKey: 'module-donations::messages.orders_description',
            sortOrder: 71,
            inSidebar: false,
        );
    }

    $access = $app->make(ModuleAdminAccessRegistry::class);
    $access->registerExact(
        moduleId: $module->id,
        routeName: 'admin.module-pages.donations.index',
        viewRoles: [AdminRole::Auditor],
        manageRoles: [AdminRole::Owner, AdminRole::Administrator],
    );
    foreach (['providers.paypal.show'] as $route) {
        $access->registerExact(moduleId: $module->id, routeName: 'admin.module-pages.donations.'.$route,
            viewRoles: [AdminRole::Auditor], manageRoles: [AdminRole::Owner, AdminRole::Administrator]);
    }
    foreach (['providers.paypal.test.return', 'providers.paypal.test.cancel'] as $route) {
        $access->registerExact(moduleId: $module->id, routeName: 'admin.module-pages.donations.'.$route,
            viewRoles: [], manageRoles: [AdminRole::Owner, AdminRole::Administrator]);
    }
    foreach (['providers.paypal.save', 'providers.paypal.check', 'providers.paypal.test.start', 'providers.paypal.test.capture'] as $route) {
        $access->registerExact(moduleId: $module->id, routeName: 'admin.module-pages.donations.'.$route,
            viewRoles: [], manageRoles: [AdminRole::Owner, AdminRole::Administrator]);
    }
    foreach (['orders.index', 'orders.show', 'providers.index', 'providers.show', 'exchange.index', 'exchange.items.preview'] as $route) {
        $access->registerExact(
            moduleId: $module->id,
            routeName: 'admin.module-pages.donations.'.$route,
            viewRoles: [AdminRole::Auditor],
            manageRoles: [AdminRole::Owner, AdminRole::Administrator],
        );
    }
    foreach (['workspace.update', 'settings.update', 'limits.update', 'rates.store', 'providers.update', 'providers.activation.update', 'providers.notification-test.start', 'providers.notification-test.status', 'providers.freekassa.test-purchase', 'orders.reviews.store', 'orders.freekassa.recovery', 'exchange.update', 'exchange.save'] as $route) {
        $access->registerExact(
            moduleId: $module->id,
            routeName: 'admin.module-pages.donations.'.$route,
            viewRoles: [],
            manageRoles: [AdminRole::Owner, AdminRole::Administrator],
        );
    }
};
