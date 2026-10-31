<?php

use App\Auth\AdminRole;
use App\Models\GameServer;
use App\Models\User;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleContext;
use App\Support\Modules\ModuleGameServerDependencyRegistry;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleUserRegistrationRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use KaevCMS\Modules\Referrals\Services\ReferralInvitationService;

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

    $app->make(ModuleUserRegistrationRegistry::class)->register(
        $module->id,
        static function (User $user, Request $request) use ($app): void {
            $app->make(ReferralInvitationService::class)->acceptForNewUser($user, $request);
        },
    );

    // All existing-user sign-ins clear the pending invitation. New registrations
    // accept inside their DB transaction before Laravel fires the Login event.
    Event::listen(Login::class, static function (Login $event) use ($app): void {
        if ($event->guard === 'web') {
            $app->make(ReferralInvitationService::class)->forget();
        }
    });

    // The player link is registered only when the player experience exists (iteration 6).
    // Until then enabling this foundation cannot lead players to an unfinished page.
    $app->make(ModuleNavigationRegistry::class)->registerAdminLink(
        moduleId: $module->id,
        routeName: 'admin.module-pages.referrals.index',
        labelKey: 'module-referrals::messages.admin_navigation_label',
        descriptionKey: 'module-referrals::messages.admin_navigation_description',
        sortOrder: 70,
    );

    $app->make(ModuleAdminAccessRegistry::class)->registerPrefix(
        moduleId: $module->id,
        routePrefix: 'admin.module-pages.referrals.',
        viewRoles: [AdminRole::Auditor],
        manageRoles: [AdminRole::Owner, AdminRole::Administrator],
    );
};
