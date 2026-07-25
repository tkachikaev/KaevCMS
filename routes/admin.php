<?php

use App\Http\Controllers\Admin\AccountSecurityController as AdminAccountSecurityController;
use App\Http\Controllers\Admin\AccountThemeController as AdminAccountThemeController;
use App\Http\Controllers\Admin\AdministratorController as AdminAdministratorController;
use App\Http\Controllers\Admin\AdministratorTwoFactorController as AdminAdministratorTwoFactorController;
use App\Http\Controllers\Admin\AdminPanelSettingsController as AdminAdminPanelSettingsController;
use App\Http\Controllers\Admin\AdminPathController as AdminAdminPathController;
use App\Http\Controllers\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Admin\Auth\AuthenticatedSessionController as AdminSessionController;
use App\Http\Controllers\Admin\Auth\TwoFactorChallengeController as AdminTwoFactorChallengeController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\GameAccountSettingsController as AdminGameAccountSettingsController;
use App\Http\Controllers\Admin\GameServerConnectionController as AdminGameServerConnectionController;
use App\Http\Controllers\Admin\GameServerController as AdminGameServerController;
use App\Http\Controllers\Admin\GeneralSettingsController as AdminGeneralSettingsController;
use App\Http\Controllers\Admin\LanguageSettingsController as AdminLanguageSettingsController;
use App\Http\Controllers\Admin\LoginServerController as AdminLoginServerController;
use App\Http\Controllers\Admin\MailDeliveryController as AdminMailDeliveryController;
use App\Http\Controllers\Admin\MailSettingsController as AdminMailSettingsController;
use App\Http\Controllers\Admin\ModuleController as AdminModuleController;
use App\Http\Controllers\Admin\NewsController as AdminNewsController;
use App\Http\Controllers\Admin\NewsImageController as AdminNewsImageController;
use App\Http\Controllers\Admin\PageController as AdminPageController;
use App\Http\Controllers\Admin\PageImageController as AdminPageImageController;
use App\Http\Controllers\Admin\QueueOperationsController as AdminQueueOperationsController;
use App\Http\Controllers\Admin\RegistrationSettingsController as AdminRegistrationSettingsController;
use App\Http\Controllers\Admin\RewardDeliveryController as AdminRewardDeliveryController;
use App\Http\Controllers\Admin\SecuritySettingsController as AdminSecuritySettingsController;
use App\Http\Controllers\Admin\SystemSettingsController as AdminSystemSettingsController;
use App\Http\Controllers\Admin\SystemUpdateController as AdminSystemUpdateController;
use App\Http\Controllers\Admin\ThemeController as AdminThemeController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Localization\LocaleController;
use Illuminate\Support\Facades\Route;

Route::pattern('adminPath', 'admin(?:-[a-z0-9]+(?:-[a-z0-9]+)*)?');

Route::prefix('{adminPath}')->name('admin.')->middleware(['admin.path', 'admin.headers'])->group(function (): void {
    Route::post('/language/{locale}', [LocaleController::class, 'admin'])
        ->where('locale', (string) config('localization.locale_pattern'))
        ->middleware('throttle:20,1')
        ->name('language.switch');

    Route::middleware('admin.guest')->group(function (): void {
        Route::get('/login', [AdminSessionController::class, 'create'])->name('login');
        Route::post('/login', [AdminSessionController::class, 'store'])
            ->middleware('throttle:admin-login-ip')
            ->name('login.store');

        Route::get('/two-factor-challenge', [AdminTwoFactorChallengeController::class, 'create'])
            ->name('two-factor.challenge');
        Route::post('/two-factor-challenge', [AdminTwoFactorChallengeController::class, 'store'])
            ->middleware('throttle:admin-two-factor-challenge')
            ->name('two-factor.challenge.store');
        Route::post('/two-factor-challenge/cancel', [AdminTwoFactorChallengeController::class, 'destroy'])
            ->name('two-factor.challenge.cancel');
    });

    Route::middleware(['admin.auth', 'admin.access'])->group(function (): void {
        Route::get('', AdminDashboardController::class)->name('dashboard');
        Route::post('/server-monitor/status', [AdminDashboardController::class, 'status'])
            ->middleware('throttle:120,1')
            ->name('server-monitor.status');
        Route::post('/server-monitor/refresh', [AdminDashboardController::class, 'refresh'])
            ->middleware('throttle:6,1')
            ->name('server-monitor.refresh');

        Route::get('/account/security', [AdminAccountSecurityController::class, 'show'])->name('account.security');
        Route::post('/account/security/two-factor/setup', [AdminAccountSecurityController::class, 'begin'])
            ->middleware('throttle:5,1')
            ->name('account.two-factor.setup');
        Route::post('/account/security/two-factor/confirm', [AdminAccountSecurityController::class, 'confirm'])
            ->middleware('throttle:5,1')
            ->name('account.two-factor.confirm');
        Route::post('/account/security/two-factor/recovery-codes', [AdminAccountSecurityController::class, 'regenerateRecoveryCodes'])
            ->middleware('throttle:3,1')
            ->name('account.two-factor.recovery-codes');
        Route::delete('/account/security/two-factor', [AdminAccountSecurityController::class, 'disable'])
            ->middleware('throttle:3,1')
            ->name('account.two-factor.disable');

        Route::get('/news', [AdminNewsController::class, 'index'])->name('news.index');
        Route::get('/news/create', [AdminNewsController::class, 'create'])->name('news.create');
        Route::post('/news/preview', [AdminNewsController::class, 'preview'])
            ->middleware('throttle:20,1')
            ->name('news.preview');
        Route::post('/news', [AdminNewsController::class, 'store'])->name('news.store');
        Route::post('/news/images', [AdminNewsImageController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('news.images.store');
        Route::get('/news/{news}/edit', [AdminNewsController::class, 'edit'])->name('news.edit');
        Route::put('/news/{news}', [AdminNewsController::class, 'update'])->name('news.update');
        Route::delete('/news/{news}', [AdminNewsController::class, 'destroy'])->name('news.destroy');

        Route::get('/pages', [AdminPageController::class, 'index'])->name('pages.index');
        Route::get('/pages/create', [AdminPageController::class, 'create'])->name('pages.create');
        Route::match(['post', 'put'], '/pages/preview', [AdminPageController::class, 'preview'])
            ->middleware('throttle:20,1')
            ->name('pages.preview');
        Route::post('/pages', [AdminPageController::class, 'store'])->name('pages.store');
        Route::post('/pages/images', [AdminPageImageController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('pages.images.store');
        Route::get('/pages/{page}/edit', [AdminPageController::class, 'edit'])->name('pages.edit');
        Route::put('/pages/{page}', [AdminPageController::class, 'update'])->name('pages.update');
        Route::delete('/pages/{page}', [AdminPageController::class, 'destroy'])->name('pages.destroy');

        Route::get('/themes', [AdminThemeController::class, 'index'])->name('themes.index');
        Route::post('/themes/{theme}/activate', [AdminThemeController::class, 'activate'])
            ->where('theme', '[a-z0-9][a-z0-9_-]*')
            ->name('themes.activate');

        Route::get('/account-themes', [AdminAccountThemeController::class, 'index'])->name('account-themes.index');
        Route::post('/account-themes/{theme}/activate', [AdminAccountThemeController::class, 'activate'])
            ->where('theme', '[a-z0-9][a-z0-9_-]*')
            ->name('account-themes.activate');

        Route::get('/reward-deliveries', [AdminRewardDeliveryController::class, 'index'])->name('rewards.index');
        Route::post('/reward-deliveries/{delivery}/reconcile', [AdminRewardDeliveryController::class, 'reconcile'])
            ->middleware('throttle:20,1')
            ->name('rewards.reconcile');

        Route::get('/modules', [AdminModuleController::class, 'index'])->name('modules.index');
        Route::post('/modules/{module}/enable', [AdminModuleController::class, 'enable'])
            ->where('module', '[a-z0-9][a-z0-9-]{0,99}')
            ->middleware('throttle:10,1')
            ->name('modules.enable');
        Route::delete('/modules/{module}/disable', [AdminModuleController::class, 'disable'])
            ->where('module', '[a-z0-9][a-z0-9-]{0,99}')
            ->middleware('throttle:10,1')
            ->name('modules.disable');

        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus'])->name('users.status');
        Route::post('/users/{user}/verification', [AdminUserController::class, 'resendVerification'])
            ->middleware('throttle:6,1')
            ->name('users.verification');
        Route::post('/users/{user}/password-reset', [AdminUserController::class, 'sendPasswordReset'])
            ->middleware('throttle:5,1')
            ->name('users.password-reset');

        Route::get('/administrators', [AdminAdministratorController::class, 'index'])->name('administrators.index');
        Route::get('/administrators/create', [AdminAdministratorController::class, 'create'])->name('administrators.create');
        Route::post('/administrators', [AdminAdministratorController::class, 'store'])->name('administrators.store');
        Route::get('/administrators/{administrator}/edit', [AdminAdministratorController::class, 'edit'])->name('administrators.edit');
        Route::put('/administrators/{administrator}', [AdminAdministratorController::class, 'update'])->name('administrators.update');
        Route::put('/administrators/{administrator}/password', [AdminAdministratorController::class, 'updatePassword'])
            ->middleware('throttle:5,1')
            ->name('administrators.password');
        Route::patch('/administrators/{administrator}/status', [AdminAdministratorController::class, 'updateStatus'])->name('administrators.status');
        Route::delete('/administrators/{administrator}/two-factor', [AdminAdministratorTwoFactorController::class, 'destroy'])
            ->middleware('throttle:3,1')
            ->name('administrators.two-factor.destroy');

        Route::get('/logs', [AdminAuditLogController::class, 'index'])->name('logs.index');
        Route::get('/logs/{auditLog}', [AdminAuditLogController::class, 'show'])->name('logs.show');

        Route::get('/settings', [AdminGeneralSettingsController::class, 'general'])->name('settings.general');
        Route::put('/settings', [AdminGeneralSettingsController::class, 'updateGeneral'])->name('settings.general.update');
        Route::get('/settings/game-server', [AdminGameServerController::class, 'index'])->name('settings.game-server');
        Route::post('/settings/game-server/{gameServer}/connection', [AdminGameServerConnectionController::class, 'update'])
            ->middleware('throttle:10,1')
            ->name('settings.game-server.connection');
        Route::get('/settings/login-server', [AdminLoginServerController::class, 'index'])->name('settings.login-server');
        Route::post('/settings/login-server', [AdminLoginServerController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('settings.login-server.store');
        Route::post('/settings/login-server/{loginServer}', [AdminLoginServerController::class, 'update'])
            ->middleware('throttle:10,1')
            ->name('settings.login-server.update');
        Route::delete('/settings/login-server/{loginServer}', [AdminLoginServerController::class, 'destroy'])
            ->name('settings.login-server.destroy');
        Route::get('/settings/registration', [AdminRegistrationSettingsController::class, 'registration'])->name('settings.registration');
        Route::put('/settings/registration', [AdminRegistrationSettingsController::class, 'updateRegistration'])->name('settings.registration.update');
        Route::get('/settings/game-accounts', [AdminGameAccountSettingsController::class, 'index'])
            ->name('settings.game-accounts');
        Route::put('/settings/game-accounts', [AdminGameAccountSettingsController::class, 'update'])
            ->name('settings.game-accounts.update');
        Route::get('/settings/mail', [AdminMailSettingsController::class, 'mail'])->name('settings.mail');
        Route::put('/settings/mail', [AdminMailSettingsController::class, 'updateMail'])->name('settings.mail.update');
        Route::post('/settings/mail/test', [AdminMailSettingsController::class, 'testMail'])
            ->middleware('throttle:5,1')
            ->name('settings.mail.test');
        Route::get('/settings/mail/delivery', [AdminMailDeliveryController::class, 'index'])
            ->name('settings.mail.delivery');
        Route::put('/settings/mail/delivery-mode', [AdminMailDeliveryController::class, 'update'])
            ->name('settings.mail.delivery-mode.update');
        Route::post('/settings/mail/delivery-probe', [AdminMailDeliveryController::class, 'probe'])
            ->middleware('throttle:4,1')
            ->name('settings.mail.delivery-probe');
        Route::get('/settings/mail/delivery-probe/status', [AdminMailDeliveryController::class, 'probeStatus'])
            ->name('settings.mail.delivery-probe.status');
        Route::get('/settings/mail/custom', [AdminMailSettingsController::class, 'customMail'])
            ->name('settings.mail.custom');
        Route::post('/settings/mail/custom', [AdminMailSettingsController::class, 'sendCustomMail'])
            ->middleware('throttle:5,1')
            ->name('settings.mail.custom.send');
        Route::get('/settings/mail/templates/{template}', [AdminMailSettingsController::class, 'mailTemplate'])
            ->where('template', 'email_verification|password_reset|password_changed')
            ->name('settings.mail.template');
        Route::put('/settings/mail/templates/{template}', [AdminMailSettingsController::class, 'updateMailTemplate'])
            ->where('template', 'email_verification|password_reset|password_changed')
            ->name('settings.mail.template.update');
        Route::post('/settings/mail/templates/{template}/reset', [AdminMailSettingsController::class, 'resetMailTemplate'])
            ->where('template', 'email_verification|password_reset|password_changed')
            ->name('settings.mail.template.reset');
        Route::post('/settings/mail/templates/{template}/test', [AdminMailSettingsController::class, 'testMailTemplate'])
            ->where('template', 'email_verification|password_reset|password_changed')
            ->middleware('throttle:5,1')
            ->name('settings.mail.template.test');
        Route::get('/settings/security', [AdminSecuritySettingsController::class, 'index'])
            ->name('settings.security');
        Route::put('/settings/security', [AdminSecuritySettingsController::class, 'update'])
            ->name('settings.security.update');
        Route::post('/settings/security/logs/cleanup', [AdminSecuritySettingsController::class, 'cleanup'])
            ->middleware('throttle:3,1')
            ->name('settings.security.logs.cleanup');
        Route::get('/settings/admin-panel', [AdminAdminPanelSettingsController::class, 'index'])
            ->name('settings.admin-panel');
        Route::put('/settings/admin-panel/monitoring', [AdminAdminPanelSettingsController::class, 'updateMonitoring'])
            ->name('settings.admin-panel.monitoring.update');
        Route::put('/settings/admin-panel/admin-path', [AdminAdminPathController::class, 'update'])
            ->name('settings.admin-panel.admin-path.update');
        Route::get('/settings/system', [AdminSystemSettingsController::class, 'system'])->name('settings.system');
        Route::get('/settings/system/updates', [AdminSystemUpdateController::class, 'index'])
            ->name('settings.system.updates.index');
        Route::post('/settings/system/updates', [AdminSystemUpdateController::class, 'store'])
            ->middleware('throttle:3,1')
            ->name('settings.system.updates.store');
        Route::get('/settings/system/updates/{systemUpdate}', [AdminSystemUpdateController::class, 'show'])
            ->name('settings.system.updates.show');
        Route::post('/settings/system/updates/{systemUpdate}/apply', [AdminSystemUpdateController::class, 'apply'])
            ->middleware('throttle:2,10')
            ->name('settings.system.updates.apply');
        Route::post('/settings/system/updates/{systemUpdate}/recover', [AdminSystemUpdateController::class, 'recover'])
            ->middleware('throttle:2,10')
            ->name('settings.system.updates.recover');
        Route::delete('/settings/system/updates/{systemUpdate}', [AdminSystemUpdateController::class, 'destroy'])
            ->name('settings.system.updates.destroy');
        Route::get('/settings/system/updates/{systemUpdate}/log', [AdminSystemUpdateController::class, 'log'])
            ->name('settings.system.updates.log');
        Route::get('/settings/system/queue', [AdminQueueOperationsController::class, 'index'])
            ->name('settings.system.queue');
        Route::post('/settings/system/queue/restart', [AdminQueueOperationsController::class, 'restart'])
            ->middleware('throttle:3,1')
            ->name('settings.system.queue.restart');
        Route::post('/settings/system/queue/cleanup', [AdminQueueOperationsController::class, 'cleanup'])
            ->middleware('throttle:3,1')
            ->name('settings.system.queue.cleanup');
        Route::post('/settings/system/queue/{uuid}/retry', [AdminQueueOperationsController::class, 'retry'])
            ->middleware('throttle:10,1')
            ->name('settings.system.queue.retry');
        Route::delete('/settings/system/queue/{uuid}', [AdminQueueOperationsController::class, 'destroy'])
            ->middleware('throttle:10,1')
            ->name('settings.system.queue.destroy');
        Route::get('/settings/languages', [AdminLanguageSettingsController::class, 'languages'])->name('settings.languages');
        Route::put('/settings/languages', [AdminLanguageSettingsController::class, 'updateLanguages'])->name('settings.languages.update');

        Route::post('/logout', [AdminSessionController::class, 'destroy'])->name('logout');
    });
});
