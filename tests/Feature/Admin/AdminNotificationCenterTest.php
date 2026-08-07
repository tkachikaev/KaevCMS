<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminPermission;
use App\Auth\AdminRole;
use App\Livewire\Admin\NotificationCenter;
use App\Models\Admin;
use App\Models\AdminNotification;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\Notifications\AdminNotificationCenter;
use App\Services\Notifications\AdminNotificationSourceScanner;
use App\Support\Modules\ModuleManager;
use App\Support\Notifications\AdminNotificationData;
use App\Support\Notifications\AdminNotificationSeverity;
use App\Support\Notifications\AdminNotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class AdminNotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_time_notifications_are_personal_permission_aware_and_idempotent(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $administrator = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $inactive = Admin::factory()->inactive()->create(['role' => AdminRole::Owner]);
        $center = app(AdminNotificationCenter::class);
        $data = $this->data();

        $this->assertSame(3, $center->notifyOnce($data, 'module-update:promo-codes:1.4.0', AdminPermission::ModulesView));
        $this->assertSame(0, $center->notifyOnce($data, 'module-update:promo-codes:1.4.0', AdminPermission::ModulesView));

        $this->assertDatabaseHas('admin_notifications', ['admin_id' => $owner->id]);
        $this->assertDatabaseHas('admin_notifications', ['admin_id' => $administrator->id]);
        $this->assertDatabaseHas('admin_notifications', ['admin_id' => $auditor->id]);
        $this->assertDatabaseMissing('admin_notifications', ['admin_id' => $editor->id]);
        $this->assertDatabaseMissing('admin_notifications', ['admin_id' => $inactive->id]);
        $this->assertSame(3, AdminNotification::query()->count());
    }

    public function test_invalid_notification_payload_fails_safely_without_breaking_the_source_action(): void
    {
        Admin::factory()->create();
        $center = app(AdminNotificationCenter::class);
        $invalid = new AdminNotificationData(
            type: AdminNotificationType::SupportTicketCreated,
            severity: AdminNotificationSeverity::Info,
            titleKey: 'Notifications',
            routeName: 'admin.dashboard',
        );

        $this->assertSame(0, $center->notifyOnce($invalid, 'invalid-route-event'));
        $this->assertSame(0, AdminNotification::query()->count());
    }

    public function test_recurring_problem_is_deduplicated_preserves_read_state_and_reopens_only_after_resolution(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $center = app(AdminNotificationCenter::class);
        $data = $this->problemData();

        $this->assertSame(1, $center->openProblem($data, 'queue-health', recipientFilter: fn (Admin $candidate): bool => $candidate->is($admin)));
        $notification = AdminNotification::query()->firstOrFail();
        $center->markRead($admin, $notification->id);
        $center->dismissAll($admin);

        $this->assertSame(0, $center->openProblem($data, 'queue-health', recipientFilter: fn (Admin $candidate): bool => $candidate->is($admin)));
        $notification->refresh();
        $this->assertSame(2, $notification->occurrences);
        $this->assertNotNull($notification->read_at);
        $this->assertNotNull($notification->dismissed_at);
        $this->assertSame(0, $center->visibleCount($admin));

        $this->assertSame(1, $center->resolveProblem('queue-health'));
        $this->assertSame(1, $center->openProblem($data, 'queue-health', recipientFilter: fn (Admin $candidate): bool => $candidate->is($admin)));

        $this->assertSame(2, AdminNotification::query()->where('admin_id', $admin->id)->count());
        $this->assertSame(1, $center->visibleCount($admin));
        $this->assertSame(1, $center->unreadCount($admin));
    }

    public function test_read_and_clear_actions_never_change_another_administrators_list(): void
    {
        $first = Admin::factory()->create();
        $second = Admin::factory()->create();
        $center = app(AdminNotificationCenter::class);

        $center->notifyOnce($this->data(), 'event:one');
        $center->notifyOnce($this->problemData(), 'event:two');

        $firstNotification = $center->inboxQuery($first)->oldest('id')->firstOrFail();
        $center->markRead($first, $firstNotification->id);
        $this->assertSame(1, $center->unreadCount($first));
        $this->assertSame(2, $center->unreadCount($second));

        $this->assertSame(1, $center->dismissRead($first));
        $this->assertSame(1, $center->visibleCount($first));
        $this->assertSame(2, $center->visibleCount($second));

        $this->assertSame(1, $center->dismissAll($first));
        $this->assertSame(0, $center->visibleCount($first));
        $this->assertSame(2, $center->visibleCount($second));
    }

    public function test_notification_routes_are_restricted_by_type_and_missing_targets_fail_closed(): void
    {
        $admin = Admin::factory()->create();

        $valid = AdminNotification::query()->create([
            'admin_id' => $admin->id,
            'type' => AdminNotificationType::ImportantOperationSucceeded,
            'severity' => AdminNotificationSeverity::Success,
            'title_key' => 'Notifications',
            'parameters' => [],
            'route_name' => 'admin.dashboard',
            'route_parameters' => [],
            'occurred_at' => now(),
            'last_occurred_at' => now(),
        ]);
        $tampered = AdminNotification::query()->create([
            'admin_id' => $admin->id,
            'type' => AdminNotificationType::SupportTicketCreated,
            'severity' => AdminNotificationSeverity::Info,
            'title_key' => 'Notifications',
            'parameters' => [],
            'route_name' => 'admin.dashboard',
            'route_parameters' => [],
            'occurred_at' => now(),
            'last_occurred_at' => now(),
        ]);
        $missingTarget = AdminNotification::query()->create([
            'admin_id' => $admin->id,
            'type' => AdminNotificationType::SupportTicketCreated,
            'severity' => AdminNotificationSeverity::Info,
            'title_key' => 'Notifications',
            'parameters' => [],
            'route_name' => 'admin.module-pages.support-tickets.show',
            'route_parameters' => ['ticket' => 999999],
            'occurred_at' => now(),
            'last_occurred_at' => now(),
        ]);

        $this->assertNotNull($valid->actionUrl());
        $this->assertNull($tampered->actionUrl());
        $this->assertNull($missingTarget->actionUrl());
    }

    public function test_livewire_center_caps_badge_at_99_and_supports_filters_and_bulk_actions(): void
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        foreach (range(1, 100) as $index) {
            AdminNotification::query()->create([
                'admin_id' => $admin->id,
                'type' => AdminNotificationType::ImportantOperationSucceeded,
                'severity' => AdminNotificationSeverity::Info,
                'external_key' => "test:{$index}",
                'title_key' => 'Notifications',
                'parameters' => [],
                'route_name' => 'admin.dashboard',
                'route_parameters' => [],
                'occurred_at' => now(),
                'last_occurred_at' => now(),
            ]);
        }

        Livewire::test(NotificationCenter::class)
            ->assertSee('99+')
            ->assertSeeHtml('data-testid="notification-unread-count"')
            ->call('setFilter', 'unread')
            ->assertSet('filter', 'unread')
            ->call('markAllRead')
            ->assertDontSeeHtml('data-testid="notification-unread-count"')
            ->assertSee(__('No unread notifications'))
            ->call('setFilter', 'all')
            ->call('clearRead')
            ->assertSee(__('No notifications'));

        $this->assertSame(0, app(AdminNotificationCenter::class)->visibleCount($admin));
        $this->assertSame(100, AdminNotification::query()->whereNotNull('dismissed_at')->count());
    }

    public function test_expired_records_are_physically_deleted_but_recent_records_are_kept(): void
    {
        $admin = Admin::factory()->create();
        $center = app(AdminNotificationCenter::class);
        Carbon::setTestNow('2026-08-01 12:00:00');

        $old = $this->createNotification($admin, now()->subDays(91));
        $recent = $this->createNotification($admin, now()->subDays(89));

        $this->assertSame(1, $center->cleanupExpired(90));
        $this->assertModelMissing($old);
        $this->assertModelExists($recent);

        Carbon::setTestNow();
    }

    public function test_installer_source_creates_one_problem_and_resolves_it_when_lock_disappears(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $lock = storage_path('app/installed.lock');
        $lockExisted = File::exists($lock);
        $previousContents = $lockExisted ? File::get($lock) : null;
        File::ensureDirectoryExists(dirname($lock));
        File::put($lock, "installed\n");

        try {
            $scanner = app(AdminNotificationSourceScanner::class);
            $this->assertDirectoryExists(public_path('install'));
            $this->assertSame(1, $scanner->scanInstaller());
            $this->assertSame(0, $scanner->scanInstaller());

            $notification = AdminNotification::query()
                ->where('admin_id', $admin->id)
                ->where('deduplication_key', 'public-installer-present')
                ->firstOrFail();
            $this->assertSame(2, $notification->occurrences);
            $this->assertNull($notification->resolved_at);

            File::delete($lock);
            $this->assertSame(0, $scanner->scanInstaller());
            $this->assertNotNull($notification->fresh()->resolved_at);
        } finally {
            if ($lockExisted && is_string($previousContents)) {
                File::put($lock, $previousContents);
            } else {
                File::delete($lock);
            }
        }
    }

    public function test_module_scanner_notifies_only_for_enabled_module_updates_and_resolves_after_approval(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $modules = app(ModuleManager::class);
        $modules->enable('support-tickets');
        DB::table('cms_modules')->where('id', 'support-tickets')->update(['version' => '1.5.2']);
        $modules->refresh();

        $scanner = app(AdminNotificationSourceScanner::class);
        $this->assertSame(1, $scanner->scanModules());
        $notification = AdminNotification::query()
            ->where('admin_id', $admin->id)
            ->where('type', AdminNotificationType::ModuleUpdateAvailable->value)
            ->firstOrFail();
        $this->assertNull($notification->resolved_at);
        $this->assertDatabaseMissing('admin_notifications', [
            'admin_id' => $admin->id,
            'type' => AdminNotificationType::ModuleMigrationPending->value,
        ]);

        DB::table('cms_modules')->where('id', 'support-tickets')->update(['version' => '1.7.2']);
        $modules->refresh();
        $this->assertSame(0, $scanner->scanModules());
        $this->assertNotNull($notification->fresh()->resolved_at);
    }

    public function test_server_scanner_deduplicates_outage_and_creates_new_events_only_after_recovery(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $loginServer = LoginServer::factory()->offline()->create(['name' => 'Login Alpha']);
        $gameServer = GameServer::factory()
            ->for($loginServer, 'loginServer')
            ->offline()
            ->create(['name' => 'Game Alpha']);
        $scanner = app(AdminNotificationSourceScanner::class);

        $this->assertSame(2, $scanner->scanServers());
        $this->assertSame(0, $scanner->scanServers());
        $this->assertSame(
            [2, 2],
            AdminNotification::query()
                ->where('admin_id', $admin->id)
                ->where('type', AdminNotificationType::ServerUnavailable->value)
                ->orderBy('id')
                ->pluck('occurrences')
                ->all(),
        );

        $loginServer->update([
            'monitor_status' => 'online',
            'database_status' => 'configured',
            'database_error' => null,
            'database_checked_at' => now(),
        ]);
        $gameServer->update([
            'monitor_status' => 'online',
            'database_status' => 'configured',
            'database_error' => null,
            'database_checked_at' => now(),
        ]);
        $this->assertSame(0, $scanner->scanServers());
        $this->assertSame(2, AdminNotification::query()->whereNotNull('resolved_at')->count());

        $loginServer->update(['monitor_status' => 'offline']);
        $gameServer->update(['monitor_status' => 'offline']);
        $this->assertSame(2, $scanner->scanServers());
        $this->assertSame(4, AdminNotification::query()->count());
        $this->assertSame(4, app(AdminNotificationCenter::class)->unreadCount($admin));
    }

    public function test_scheduler_and_frontend_assets_keep_notification_center_automatic(): void
    {
        $schedule = File::get(base_path('routes/console.php'));
        $layout = File::get(resource_path('views/admin/layouts/panel.blade.php'));
        $appLayout = File::get(resource_path('views/admin/layouts/app.blade.php'));
        $javascript = File::get(public_path('assets/admin/js/notifications.js'));

        $this->assertStringContainsString("Schedule::command('kaevcms:notifications-scan')", $schedule);
        $this->assertStringContainsString("Schedule::command('kaevcms:notifications-clean')", $schedule);
        $this->assertStringContainsString('<livewire:admin.notification-center />', $layout);
        $this->assertStringContainsString('assets/admin/js/notifications.js', $appLayout);
        $this->assertStringContainsString('wire:ignore.self', File::get(resource_path('views/livewire/admin/notification-center.blade.php')));
        $this->assertStringContainsString('data-admin-notification-center', $javascript);
    }

    private function data(): AdminNotificationData
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

    private function problemData(): AdminNotificationData
    {
        return new AdminNotificationData(
            type: AdminNotificationType::QueueProblem,
            severity: AdminNotificationSeverity::Error,
            titleKey: 'The queue requires attention',
            messageKey: 'The queue or Scheduler is not processing jobs correctly.',
            routeName: 'admin.settings.system.queue',
        );
    }

    private function createNotification(Admin $admin, Carbon $occurredAt): AdminNotification
    {
        return AdminNotification::query()->create([
            'admin_id' => $admin->id,
            'type' => AdminNotificationType::ImportantOperationSucceeded,
            'severity' => AdminNotificationSeverity::Info,
            'title_key' => 'Notifications',
            'parameters' => [],
            'occurred_at' => $occurredAt,
            'last_occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }
}
