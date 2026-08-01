<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\AdminNotification;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\Notifications\AdminNotificationCenter;
use App\Services\Notifications\AdminNotificationSettings;
use App\Services\Notifications\AdminNotificationSourceScanner;
use App\Support\Notifications\AdminNotificationData;
use App\Support\Notifications\AdminNotificationSeverity;
use App\Support\Notifications\AdminNotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class AdminNotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_reuses_existing_switch_and_tooltip_components(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $this->actingAs($owner, 'admin')
            ->get(route('admin.settings.notifications'))
            ->assertOk()
            ->assertSee(__('Notification types'))
            ->assertSee(__('Technical Support module'))
            ->assertSee(__('Background tasks'))
            ->assertSee(__('Low disk space'))
            ->assertSee('settings-toggle-row', false)
            ->assertSee('switch-control', false)
            ->assertSee('field-help-tooltip', false)
            ->assertSee('notification_retention_days', false)
            ->assertSee('data-testid="notification-settings-link"', false);
    }

    public function test_owner_can_save_notification_types_and_retention_settings(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $this->actingAs($owner, 'admin')
            ->put(route('admin.settings.notifications.update'), [
                'notification_support' => '1',
                'notification_modules' => '0',
                'notification_cms_updates' => '1',
                'notification_background_tasks' => '0',
                'notification_login_server' => '1',
                'notification_game_server' => '0',
                'notification_disk_space' => '1',
                'notification_installer' => '0',
                'notification_diagnostics' => '1',
                'notification_auto_cleanup' => '1',
                'notification_retention_days' => '180',
            ])
            ->assertRedirect(route('admin.settings.notifications'))
            ->assertSessionHas('status', __('Notification settings saved.'));

        $this->assertDatabaseHas('cms_settings', [
            'key' => 'admin_notifications.type.support',
            'value' => '1',
        ]);
        $this->assertDatabaseHas('cms_settings', [
            'key' => 'admin_notifications.type.modules',
            'value' => '0',
        ]);
        $this->assertDatabaseHas('cms_settings', [
            'key' => 'admin_notifications.type.game_server',
            'value' => '0',
        ]);
        $this->assertDatabaseHas('cms_settings', [
            'key' => 'admin_notifications.auto_cleanup',
            'value' => '1',
        ]);
        $this->assertDatabaseHas('cms_settings', [
            'key' => 'admin_notifications.retention_days',
            'value' => '180',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settings.notifications_updated',
        ]);
    }

    public function test_invalid_retention_period_is_rejected(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $this->actingAs($owner, 'admin')
            ->from(route('admin.settings.notifications'))
            ->put(route('admin.settings.notifications.update'), [
                ...$this->validPayload(),
                'notification_retention_days' => '365',
            ])
            ->assertRedirect(route('admin.settings.notifications'))
            ->assertSessionHasErrors('notification_retention_days');
    }

    public function test_auditor_can_view_but_cannot_change_notification_settings(): void
    {
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);

        $this->actingAs($auditor, 'admin')
            ->get(route('admin.settings.notifications'))
            ->assertOk()
            ->assertSee(__('Read-only mode'));

        $this->actingAs($auditor, 'admin')
            ->put(route('admin.settings.notifications.update'), $this->validPayload())
            ->assertForbidden();
    }

    public function test_editor_does_not_receive_notification_settings_link(): void
    {
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);

        $this->actingAs($editor, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('data-testid="notification-settings-link"', false);
    }

    public function test_disabled_one_time_type_stops_new_events_without_deleting_existing_notifications(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $center = app(AdminNotificationCenter::class);
        $settings = app(AdminNotificationSettings::class);
        $data = $this->moduleUpdateData();

        $this->assertSame(1, $center->notifyOnce($data, 'module-update:first'));
        $this->updateCategory($settings, AdminNotificationSettings::CATEGORY_MODULES, false);
        $this->assertSame(0, $center->notifyOnce($data, 'module-update:second'));

        $this->assertDatabaseHas('admin_notifications', [
            'admin_id' => $owner->id,
            'external_key' => 'module-update:first',
        ]);
        $this->assertDatabaseMissing('admin_notifications', [
            'admin_id' => $owner->id,
            'external_key' => 'module-update:second',
        ]);
    }

    public function test_support_ticket_creation_and_player_reply_use_one_support_setting(): void
    {
        Admin::factory()->create(['role' => AdminRole::Owner]);
        $settings = app(AdminNotificationSettings::class);
        $this->updateCategory($settings, AdminNotificationSettings::CATEGORY_SUPPORT, false);
        $center = app(AdminNotificationCenter::class);

        foreach ([
            AdminNotificationType::SupportTicketCreated,
            AdminNotificationType::SupportTicketPlayerReply,
        ] as $index => $type) {
            $created = $center->notifyOnce(
                new AdminNotificationData(
                    type: $type,
                    severity: AdminNotificationSeverity::Info,
                    titleKey: 'Notifications',
                    routeName: 'admin.module-pages.support-tickets.show',
                    routeParameters: ['ticket' => $index + 1],
                ),
                'support-event:'.($index + 1),
            );

            $this->assertSame(0, $created);
        }

        $this->assertSame(0, AdminNotification::query()->count());
    }

    public function test_reenabling_problem_type_creates_a_new_event_when_problem_still_exists(): void
    {
        Admin::factory()->create(['role' => AdminRole::Owner]);
        $center = app(AdminNotificationCenter::class);
        $settings = app(AdminNotificationSettings::class);
        $data = $this->queueProblemData();

        $this->assertSame(1, $center->openProblem($data, 'queue-health'));
        $this->updateCategory($settings, AdminNotificationSettings::CATEGORY_BACKGROUND_TASKS, false);
        $this->assertSame(0, $center->openProblem($data, 'queue-health'));
        $this->assertSame(1, AdminNotification::query()->whereNotNull('resolved_at')->count());

        $this->updateCategory($settings, AdminNotificationSettings::CATEGORY_BACKGROUND_TASKS, true);
        $this->assertSame(1, $center->openProblem($data, 'queue-health'));
        $this->assertSame(2, AdminNotification::query()->count());
        $this->assertSame(1, AdminNotification::query()->whereNull('resolved_at')->count());
    }

    public function test_loginserver_and_gameserver_availability_can_be_configured_separately(): void
    {
        Admin::factory()->create(['role' => AdminRole::Owner]);
        $loginServer = LoginServer::factory()->offline()->create(['name' => 'Login Alpha']);
        GameServer::factory()
            ->for($loginServer, 'loginServer')
            ->offline()
            ->create(['name' => 'Game Alpha']);
        $settings = app(AdminNotificationSettings::class);
        $this->updateCategory($settings, AdminNotificationSettings::CATEGORY_LOGIN_SERVER, false);

        $this->assertSame(1, app(AdminNotificationSourceScanner::class)->scanServers());
        $this->assertDatabaseMissing('admin_notifications', [
            'route_name' => 'admin.settings.login-server',
        ]);
        $this->assertDatabaseHas('admin_notifications', [
            'route_name' => 'admin.settings.game-server',
        ]);

        $this->updateCategory($settings, AdminNotificationSettings::CATEGORY_LOGIN_SERVER, true);
        $this->assertSame(1, app(AdminNotificationSourceScanner::class)->scanServers());
        $this->assertDatabaseHas('admin_notifications', [
            'route_name' => 'admin.settings.login-server',
        ]);
    }

    public function test_automatic_cleanup_can_be_disabled_but_manual_override_still_works(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $settings = app(AdminNotificationSettings::class);
        $values = $settings->values();
        $settings->update($values['categories'], false, 30);
        Carbon::setTestNow('2026-08-01 12:00:00');

        $notification = AdminNotification::query()->create([
            'admin_id' => $admin->id,
            'type' => AdminNotificationType::ImportantOperationSucceeded,
            'severity' => AdminNotificationSeverity::Info,
            'title_key' => 'Notifications',
            'parameters' => [],
            'occurred_at' => now()->subDays(31),
            'last_occurred_at' => now()->subDays(31),
        ]);

        $center = app(AdminNotificationCenter::class);
        $this->assertSame(0, $center->cleanupExpired());
        $this->assertModelExists($notification);
        $this->assertSame(1, $center->cleanupExpired(30));
        $this->assertModelMissing($notification);

        Carbon::setTestNow();
    }

    /** @return array<string, string> */
    private function validPayload(): array
    {
        return [
            'notification_support' => '1',
            'notification_modules' => '1',
            'notification_cms_updates' => '1',
            'notification_background_tasks' => '1',
            'notification_login_server' => '1',
            'notification_game_server' => '1',
            'notification_disk_space' => '1',
            'notification_installer' => '1',
            'notification_diagnostics' => '1',
            'notification_auto_cleanup' => '1',
            'notification_retention_days' => '90',
        ];
    }

    private function updateCategory(
        AdminNotificationSettings $settings,
        string $category,
        bool $enabled,
    ): void {
        $values = $settings->values();
        $categories = $values['categories'];
        $categories[$category] = $enabled;
        $settings->update($categories, $values['auto_cleanup'], $values['retention_days']);
    }

    private function moduleUpdateData(): AdminNotificationData
    {
        return new AdminNotificationData(
            type: AdminNotificationType::ModuleUpdateAvailable,
            severity: AdminNotificationSeverity::Info,
            titleKey: 'A module update is available',
            messageKey: 'Module :module requires an update.',
            parameters: ['module' => 'Promo Codes'],
            routeName: 'admin.modules.index',
        );
    }

    private function queueProblemData(): AdminNotificationData
    {
        return new AdminNotificationData(
            type: AdminNotificationType::QueueProblem,
            severity: AdminNotificationSeverity::Error,
            titleKey: 'The queue requires attention',
            messageKey: 'The queue or Scheduler is not processing jobs correctly.',
            routeName: 'admin.settings.system.queue',
        );
    }
}
