<?php

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\News;
use App\Support\Modules\ModuleAdminAccessLevel;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\News\NewsExtensionRegistry;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schedule;
use KaevCMS\Modules\NewsComments\Console\Commands\CleanupNewsCommentEventsCommand;
use KaevCMS\Modules\NewsComments\Livewire\NewsThread;
use KaevCMS\Modules\NewsComments\Services\NewsCommentSettings;
use Livewire\Livewire;

return static function (Application $app, ModuleContext $module): void {
    Livewire::component('news-comments.thread', NewsThread::class);

    $app->make(NewsExtensionRegistry::class)->register(
        $module->id,
        static function (News $news) use ($app): ?View {
            if (! $app->make(NewsCommentSettings::class)->enabledForNews($news)) {
                return null;
            }

            return view('module-news-comments::public.index', ['news' => $news]);
        },
    );

    $app->make(ModuleNavigationRegistry::class)->registerAdminLink(
        moduleId: $module->id,
        routeName: 'admin.module-pages.news-comments.index',
        labelKey: 'module-news-comments::messages.navigation_label',
        descriptionKey: 'module-news-comments::messages.navigation_description',
        sortOrder: 75,
    );

    $access = $app->make(ModuleAdminAccessRegistry::class);
    $access->registerExact(
        moduleId: $module->id,
        routeName: 'admin.module-pages.news-comments.index',
        viewRoles: [AdminRole::Auditor],
        manageRoles: [AdminRole::Owner, AdminRole::Administrator],
        additionalAccess: static fn (Admin $admin): ModuleAdminAccessLevel => $admin->role === AdminRole::Editor
            && $app->make(NewsCommentSettings::class)->editorCanModerate()
                ? ModuleAdminAccessLevel::Read
                : ModuleAdminAccessLevel::Denied,
    );

    foreach (['settings.update', 'news.update'] as $action) {
        $access->registerExact(
            moduleId: $module->id,
            routeName: 'admin.module-pages.news-comments.'.$action,
            viewRoles: [],
            manageRoles: [AdminRole::Owner],
        );
    }

    if ($app->runningInConsole()) {
        ArtisanApplication::starting(static function (ArtisanApplication $artisan): void {
            $artisan->resolveCommands([CleanupNewsCommentEventsCommand::class]);
        });
        Schedule::command('kaevcms:news-comments-clean-events --batch=1000')
            ->dailyAt('04:25')
            ->withoutOverlapping();
    }
};
