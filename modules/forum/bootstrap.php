<?php

use App\Auth\AdminRole;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\CmsSettings;
use App\Services\Html\SafeHtmlSanitizer;
use App\Services\Notifications\PlayerNotificationCenter;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Themes\ThemeValidator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use KaevCMS\Modules\Forum\Services\ForumImageHosts;
use KaevCMS\Modules\Forum\Services\ForumNotifications;
use KaevCMS\Modules\Forum\Services\ForumThemes;

return static function (Application $app, ModuleContext $module): void {
    // Image hosts come from the forum settings; read once per request.
    $app->scoped(ForumImageHosts::class);
    $app->scoped(ForumNotifications::class);
    // Forum themes are style-only folders in public/forum-themes; the module version
    // lets a theme ask for a minimum forum version.
    $app->scoped(ForumThemes::class, static fn (Application $app): ForumThemes => new ForumThemes(
        $app->make(CmsSettings::class),
        $app->make(ThemeValidator::class),
        $app->make(Filesystem::class),
        (string) ($module->manifest['version'] ?? '0.0.0'),
    ));
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

    // Forum themes sit under Admin → Themes and follow the core appearance roles:
    // the owner and administrators switch them, auditors may look.
    $navigation->registerAppearanceLink(
        moduleId: $module->id,
        routeName: 'admin.module-pages.forum.themes',
        labelKey: 'module-forum::messages.themes_navigation',
        descriptionKey: 'module-forum::messages.themes_description',
    );
    foreach (['themes', 'themes.activate'] as $themeRoute) {
        $app->make(ModuleAdminAccessRegistry::class)->registerExact(
            moduleId: $module->id,
            routeName: 'admin.module-pages.forum.'.$themeRoute,
            viewRoles: [AdminRole::Auditor],
            manageRoles: [AdminRole::Owner, AdminRole::Administrator],
        );
    }

    $app->make(ModuleAdminAccessRegistry::class)->registerExact(
        moduleId: $module->id,
        routeName: 'admin.module-pages.forum.settings',
        viewRoles: [],
        manageRoles: [AdminRole::Owner],
    );

    foreach (['settings.update', 'community.update', 'moderators.store', 'moderators.destroy'] as $action) {
        $app->make(ModuleAdminAccessRegistry::class)->registerExact(
            moduleId: $module->id,
            routeName: 'admin.module-pages.forum.'.$action,
            viewRoles: [],
            manageRoles: [AdminRole::Owner],
        );
    }
};
