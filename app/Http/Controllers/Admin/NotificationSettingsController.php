<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithSettingsAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveAdminNotificationSettingsRequest;
use App\Services\AuditLogger;
use App\Services\Notifications\AdminNotificationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class NotificationSettingsController extends Controller
{
    use InteractsWithSettingsAudit;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(AdminNotificationSettings $settings): View
    {
        return view('admin.settings.notifications', [
            'settings' => $settings->values(),
            'retentionOptions' => $settings->retentionOptions(),
            'notificationTypes' => $this->notificationTypes(),
        ]);
    }

    public function update(
        SaveAdminNotificationSettingsRequest $request,
        AdminNotificationSettings $settings,
    ): RedirectResponse {
        $before = $settings->values();
        $settings->update(
            categories: [
                AdminNotificationSettings::CATEGORY_SUPPORT => $request->boolean('notification_support'),
                AdminNotificationSettings::CATEGORY_MODULES => $request->boolean('notification_modules'),
                AdminNotificationSettings::CATEGORY_CMS_UPDATES => $request->boolean('notification_cms_updates'),
                AdminNotificationSettings::CATEGORY_BACKGROUND_TASKS => $request->boolean('notification_background_tasks'),
                AdminNotificationSettings::CATEGORY_LOGIN_SERVER => $request->boolean('notification_login_server'),
                AdminNotificationSettings::CATEGORY_GAME_SERVER => $request->boolean('notification_game_server'),
                AdminNotificationSettings::CATEGORY_DISK_SPACE => $request->boolean('notification_disk_space'),
                AdminNotificationSettings::CATEGORY_INSTALLER => $request->boolean('notification_installer'),
                AdminNotificationSettings::CATEGORY_DIAGNOSTICS => $request->boolean('notification_diagnostics'),
            ],
            autoCleanup: $request->boolean('notification_auto_cleanup'),
            retentionDays: $request->integer('notification_retention_days'),
        );
        $after = $settings->values();

        $this->auditLogger->success(
            category: 'admin',
            action: 'settings.notifications_updated',
            target: __('Notification settings'),
            details: ['changes' => $this->auditChanges($before, $after)],
        );

        return redirect()
            ->route('admin.settings.notifications')
            ->with('status', __('Notification settings saved.'));
    }

    /**
     * @return list<array{category:string, field:string, label:string, help:string}>
     */
    private function notificationTypes(): array
    {
        return [
            [
                'category' => AdminNotificationSettings::CATEGORY_SUPPORT,
                'field' => 'notification_support',
                'label' => __('Technical Support module'),
                'help' => __('New requests and player replies in the Technical Support module.'),
            ],
            [
                'category' => AdminNotificationSettings::CATEGORY_MODULES,
                'field' => 'notification_modules',
                'label' => __('Module updates'),
                'help' => __('A module update is available or a module database update must be applied.'),
            ],
            [
                'category' => AdminNotificationSettings::CATEGORY_CMS_UPDATES,
                'field' => 'notification_cms_updates',
                'label' => __('KaevCMS updates'),
                'help' => __('A KaevCMS update failed or the CMS database update must be completed.'),
            ],
            [
                'category' => AdminNotificationSettings::CATEGORY_BACKGROUND_TASKS,
                'field' => 'notification_background_tasks',
                'label' => __('Background tasks'),
                'help' => __('The task scheduler, job queue or background operation processing requires attention.'),
            ],
            [
                'category' => AdminNotificationSettings::CATEGORY_LOGIN_SERVER,
                'field' => 'notification_login_server',
                'label' => __('LoginServer availability'),
                'help' => __('KaevCMS cannot connect to a configured LoginServer or its database.'),
            ],
            [
                'category' => AdminNotificationSettings::CATEGORY_GAME_SERVER,
                'field' => 'notification_game_server',
                'label' => __('GameServer availability'),
                'help' => __('KaevCMS cannot connect to a configured GameServer or its database.'),
            ],
            [
                'category' => AdminNotificationSettings::CATEGORY_DISK_SPACE,
                'field' => 'notification_disk_space',
                'label' => __('Low disk space'),
                'help' => __('The server disk is running out of space for website files, logs, updates and backups.'),
            ],
            [
                'category' => AdminNotificationSettings::CATEGORY_INSTALLER,
                'field' => 'notification_installer',
                'label' => __('Installer not removed'),
                'help' => __('The public KaevCMS installation directory is still available after setup.'),
            ],
            [
                'category' => AdminNotificationSettings::CATEGORY_DIAGNOSTICS,
                'field' => 'notification_diagnostics',
                'label' => __('Critical system problems'),
                'help' => __('System diagnostics found a serious configuration, security or environment problem.'),
            ],
        ];
    }
}
