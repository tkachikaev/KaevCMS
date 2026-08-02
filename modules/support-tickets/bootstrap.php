<?php

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Support\Modules\ModuleAdminAccessLevel;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Schedule;
use KaevCMS\Modules\SupportTickets\Console\Commands\CleanupSupportTicketsCommand;
use KaevCMS\Modules\SupportTickets\Livewire\AccountTicketConversation;
use KaevCMS\Modules\SupportTickets\Livewire\AccountTicketIndex;
use KaevCMS\Modules\SupportTickets\Livewire\AdminTicketConversation;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketAttentionCounter;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings;
use Livewire\Livewire;

return static function (Application $app, ModuleContext $module): void {
    Livewire::component('support-tickets.account-index', AccountTicketIndex::class);
    Livewire::component('support-tickets.account-conversation', AccountTicketConversation::class);
    Livewire::component('support-tickets.admin-conversation', AdminTicketConversation::class);

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
        badgeResolver: static fn (Admin $admin): int => $app->make(SupportTicketAttentionCounter::class)->countFor($admin),
    );

    $settings = static fn (): SupportTicketSettings => $app->make(SupportTicketSettings::class);
    $editorView = static fn (Admin $admin): ModuleAdminAccessLevel => $admin->role === AdminRole::Editor
        && $settings()->editorCanView()
            ? ModuleAdminAccessLevel::Read
            : ModuleAdminAccessLevel::Denied;
    $editorReply = static fn (Admin $admin): ModuleAdminAccessLevel => $admin->role === AdminRole::Editor
        && $settings()->editorCanReply()
            ? ModuleAdminAccessLevel::Manage
            : ModuleAdminAccessLevel::Denied;
    $editorNotes = static fn (Admin $admin): ModuleAdminAccessLevel => $admin->role === AdminRole::Editor
        && $settings()->editorCanAddInternalNotes()
            ? ModuleAdminAccessLevel::Manage
            : ModuleAdminAccessLevel::Denied;
    $editorMessageEdit = static fn (Admin $admin): ModuleAdminAccessLevel => $admin->role === AdminRole::Editor
        && ($settings()->editorCanReply() || $settings()->editorCanAddInternalNotes())
            ? ModuleAdminAccessLevel::Manage
            : ModuleAdminAccessLevel::Denied;

    $access = $app->make(ModuleAdminAccessRegistry::class);
    foreach ([
        'admin.module-pages.support-tickets.settings',
        'admin.module-pages.support-tickets.settings.update',
        'admin.module-pages.support-tickets.settings.cleanup-preview',
        'admin.module-pages.support-tickets.settings.cleanup',
    ] as $routeName) {
        $access->registerExact(
            moduleId: $module->id,
            routeName: $routeName,
            viewRoles: [],
            manageRoles: [AdminRole::Owner],
        );
    }

    foreach ([
        'admin.module-pages.support-tickets.index',
        'admin.module-pages.support-tickets.show',
    ] as $routeName) {
        $access->registerExact(
            moduleId: $module->id,
            routeName: $routeName,
            viewRoles: [AdminRole::Auditor],
            manageRoles: [AdminRole::Owner, AdminRole::Administrator],
            additionalAccess: $editorView,
        );
    }

    foreach ([
        'admin.module-pages.support-tickets.assign',
        'admin.module-pages.support-tickets.reply',
        'admin.module-pages.support-tickets.close',
        'admin.module-pages.support-tickets.reopen',
    ] as $routeName) {
        $access->registerExact(
            moduleId: $module->id,
            routeName: $routeName,
            viewRoles: [],
            manageRoles: [AdminRole::Owner, AdminRole::Administrator],
            additionalAccess: $editorReply,
        );
    }

    $access->registerExact(
        moduleId: $module->id,
        routeName: 'admin.module-pages.support-tickets.retention-protection',
        viewRoles: [],
        manageRoles: [AdminRole::Owner, AdminRole::Administrator],
    );

    $access->registerExact(
        moduleId: $module->id,
        routeName: 'admin.module-pages.support-tickets.destroy',
        viewRoles: [],
        manageRoles: [AdminRole::Owner],
    );

    $access->registerExact(
        moduleId: $module->id,
        routeName: 'admin.module-pages.support-tickets.note',
        viewRoles: [],
        manageRoles: [AdminRole::Owner, AdminRole::Administrator],
        additionalAccess: $editorNotes,
    );
    $access->registerExact(
        moduleId: $module->id,
        routeName: 'admin.module-pages.support-tickets.messages.update',
        viewRoles: [],
        manageRoles: [AdminRole::Owner, AdminRole::Administrator],
        additionalAccess: $editorMessageEdit,
    );
    $access->registerPrefix(
        moduleId: $module->id,
        routePrefix: 'admin.module-pages.support-tickets.',
        viewRoles: [AdminRole::Auditor],
        manageRoles: [AdminRole::Owner, AdminRole::Administrator],
    );

    if ($app->runningInConsole()) {
        ArtisanApplication::starting(static function (ArtisanApplication $artisan): void {
            $artisan->resolveCommands([CleanupSupportTicketsCommand::class]);
        });
        Schedule::command('kaevcms:support-tickets-cleanup --scheduled')
            ->dailyAt('04:05')
            ->withoutOverlapping();
    }
};
