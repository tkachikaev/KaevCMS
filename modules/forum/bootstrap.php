<?php

use App\Auth\AdminRole;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Html\SafeHtmlSanitizer;
use App\Services\Notifications\PlayerNotificationCenter;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Contracts\Foundation\Application;
use KaevCMS\Modules\Forum\Services\ForumImageHosts;
use KaevCMS\Modules\Forum\Services\ForumNotifications;

return static function (Application $app, ModuleContext $module): void {
    // Image hosts come from the forum settings; read once per request.
    $app->scoped(ForumImageHosts::class);
    $app->scoped(ForumNotifications::class);
    SafeHtmlSanitizer::resolveForumImageHostsUsing(static fn (): array => app(ForumImageHosts::class)->all());
    // Keep the notification center independent from the forum module. The active
    // module installs its access policy for each resolved center.
    $app->afterResolving(PlayerNotificationCenter::class, static function (PlayerNotificationCenter $center): void {
        $center->registerVisibilityPolicy('forum.', static fn (User $user, UserNotification $notification, array &$memo): bool => app(ForumNotifications::class)
            ->canReadNotification($user, $notification, $memo));
    });

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

    foreach (['settings.update', 'images.update', 'moderators.store', 'moderators.destroy'] as $action) {
        $app->make(ModuleAdminAccessRegistry::class)->registerExact(
            moduleId: $module->id,
            routeName: 'admin.module-pages.forum.'.$action,
            viewRoles: [],
            manageRoles: [AdminRole::Owner],
        );
    }
};
