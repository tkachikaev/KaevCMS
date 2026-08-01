<?php

namespace App\Services\Notifications;

use App\Auth\AdminPermission;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Models\SystemUpdate;
use App\Services\Infrastructure\RuntimeDiagnostics;
use App\Services\Security\EncryptionHealth;
use App\Support\Modules\ModuleManager;
use App\Support\Notifications\AdminNotificationData;
use App\Support\Notifications\AdminNotificationSeverity;
use App\Support\Notifications\AdminNotificationType;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class AdminNotificationSourceScanner
{
    public function __construct(
        private readonly AdminNotificationCenter $notifications,
        private readonly ModuleManager $modules,
        private readonly RuntimeDiagnostics $runtimeDiagnostics,
        private readonly EncryptionHealth $encryptionHealth,
        private readonly Migrator $migrator,
    ) {}

    /** @return array<string, int> */
    public function scan(): array
    {
        if (! $this->notifications->available()) {
            return [];
        }

        return [
            'modules' => $this->scanModules(),
            'core_migrations' => $this->scanCoreMigrations(),
            'queue' => $this->scanQueue(),
            'servers' => $this->scanServers(),
            'updates' => $this->scanFailedUpdates(),
            'installer' => $this->scanInstaller(),
            'disk' => $this->scanDisk(),
            'diagnostics' => $this->scanDiagnostics(),
        ];
    }

    public function scanModules(): int
    {
        $created = 0;

        try {
            foreach ($this->modules->installed() as $module) {
                $id = trim((string) ($module['id'] ?? ''));
                if ($id === '') {
                    continue;
                }

                $name = trim((string) ($module['name'] ?? $id));
                $status = (string) ($module['status'] ?? 'unknown');
                $enabled = ($module['enabled'] ?? false) === true;
                $updateAvailable = $enabled && (($module['update_available'] ?? false) === true || $status === 'update_pending');
                $migrationPending = $enabled && max(0, (int) ($module['pending_count'] ?? 0)) > 0;
                $updateKey = "module-update:{$id}";
                $migrationKey = "module-migration:{$id}";

                if ($updateAvailable) {
                    $created += $this->notifications->openProblem(
                        new AdminNotificationData(
                            type: AdminNotificationType::ModuleUpdateAvailable,
                            severity: AdminNotificationSeverity::Info,
                            titleKey: 'A module update is available',
                            messageKey: 'Module :module requires an update.',
                            parameters: ['module' => $name],
                            routeName: 'admin.modules.index',
                        ),
                        $updateKey,
                        AdminPermission::ModulesView,
                    );
                } else {
                    $this->notifications->resolveProblem($updateKey);
                }

                if ($migrationPending) {
                    $created += $this->notifications->openProblem(
                        new AdminNotificationData(
                            type: AdminNotificationType::ModuleMigrationPending,
                            severity: AdminNotificationSeverity::Warning,
                            titleKey: 'A module migration is pending',
                            messageKey: 'Module :module has pending database migrations.',
                            parameters: ['module' => $name],
                            routeName: 'admin.modules.index',
                        ),
                        $migrationKey,
                        AdminPermission::ModulesView,
                    );
                } else {
                    $this->notifications->resolveProblem($migrationKey);
                }
            }
        } catch (Throwable) {
            return $created;
        }

        return $created;
    }

    public function scanCoreMigrations(): int
    {
        $key = 'core-migrations';

        try {
            if (! Schema::hasTable('migrations')) {
                return 0;
            }

            $files = $this->migrator->getMigrationFiles(database_path('migrations'));
            $ran = $this->migrator->getRepository()->getRan();
            $pending = array_diff(array_keys($files), $ran);
            $count = count($pending);

            if ($count < 1) {
                $this->notifications->resolveProblem($key);

                return 0;
            }

            return $this->notifications->openProblem(
                new AdminNotificationData(
                    type: AdminNotificationType::CoreMigrationPending,
                    severity: AdminNotificationSeverity::Warning,
                    titleKey: 'A CMS migration is pending',
                    messageKey: 'Pending CMS database migrations: :count.',
                    parameters: ['count' => $count],
                    routeName: 'admin.settings.system',
                ),
                $key,
                AdminPermission::SystemView,
            );
        } catch (Throwable) {
            return 0;
        }
    }

    public function scanQueue(): int
    {
        $key = 'queue-health';

        try {
            $overview = $this->runtimeDiagnostics->overview();
            $state = $overview['overall_state'];
            if (! in_array($state, ['danger', 'warning'], true)) {
                $this->notifications->resolveProblem($key);

                return 0;
            }

            $failed = max(0, $overview['jobs']['failed']);
            $severity = $state === 'danger'
                ? AdminNotificationSeverity::Error
                : AdminNotificationSeverity::Warning;

            return $this->notifications->openProblem(
                new AdminNotificationData(
                    type: AdminNotificationType::QueueProblem,
                    severity: $severity,
                    titleKey: 'The queue requires attention',
                    messageKey: $failed > 0
                        ? 'Failed queue jobs: :count.'
                        : 'The queue or Scheduler is not processing jobs correctly.',
                    parameters: ['count' => $failed],
                    routeName: 'admin.settings.system.queue',
                ),
                $key,
                AdminPermission::QueueView,
            );
        } catch (Throwable) {
            return 0;
        }
    }

    public function scanServers(): int
    {
        $created = 0;

        try {
            $loginServers = LoginServer::query()->orderBy('id')->get();
            foreach ($loginServers as $server) {
                $key = "login-server-unavailable:{$server->id}";
                if (! $this->loginServerConfigured($server) || ! $this->serverUnavailable($server)) {
                    $this->notifications->resolveProblem($key);

                    continue;
                }

                $created += $this->notifications->openProblem(
                    new AdminNotificationData(
                        type: AdminNotificationType::ServerUnavailable,
                        severity: AdminNotificationSeverity::Error,
                        titleKey: 'LoginServer is unavailable',
                        messageKey: 'Configured LoginServer :server is unavailable or its database is incompatible.',
                        parameters: ['server' => $server->name],
                        routeName: 'admin.settings.login-server',
                    ),
                    $key,
                    AdminPermission::ServersView,
                );
            }

            $gameServers = GameServer::query()->with('loginServer')->orderBy('id')->get();
            foreach ($gameServers as $server) {
                $key = "game-server-unavailable:{$server->id}";
                if (! $server->connectionConfigured() || $server->maintenance_enabled || ! $this->serverUnavailable($server)) {
                    $this->notifications->resolveProblem($key);

                    continue;
                }

                $created += $this->notifications->openProblem(
                    new AdminNotificationData(
                        type: AdminNotificationType::ServerUnavailable,
                        severity: AdminNotificationSeverity::Error,
                        titleKey: 'GameServer is unavailable',
                        messageKey: 'Configured GameServer :server is unavailable or its database is incompatible.',
                        parameters: ['server' => $server->name],
                        routeName: 'admin.settings.game-server',
                    ),
                    $key,
                    AdminPermission::ServersView,
                );
            }
        } catch (Throwable) {
            return $created;
        }

        return $created;
    }

    public function scanFailedUpdates(): int
    {
        $created = 0;

        try {
            $updates = SystemUpdate::query()
                ->where('status', SystemUpdate::STATUS_FAILED)
                ->where('completed_at', '>=', now()->subDays(30))
                ->orderBy('id')
                ->get();

            foreach ($updates as $update) {
                $created += $this->notifications->notifyOnce(
                    new AdminNotificationData(
                        type: AdminNotificationType::SystemUpdateFailed,
                        severity: AdminNotificationSeverity::Error,
                        titleKey: 'KaevCMS update failed',
                        messageKey: 'Update to version :version did not complete successfully.',
                        parameters: ['version' => $update->target_version],
                        routeName: 'admin.settings.system.updates.show',
                        routeParameters: ['systemUpdate' => $update->id],
                    ),
                    "system-update-failed:{$update->uuid}",
                    AdminPermission::SystemUpdatesView,
                );
            }
        } catch (Throwable) {
            return $created;
        }

        return $created;
    }

    public function scanInstaller(): int
    {
        $key = 'public-installer-present';
        $present = is_file(storage_path('app/installed.lock')) && is_dir(public_path('install'));

        if (! $present) {
            $this->notifications->resolveProblem($key);

            return 0;
        }

        return $this->notifications->openProblem(
            new AdminNotificationData(
                type: AdminNotificationType::InstallerPresent,
                severity: AdminNotificationSeverity::Error,
                titleKey: 'The public installer is still available',
                messageKey: 'Remove the public install directory immediately.',
                routeName: 'admin.settings.system',
            ),
            $key,
            AdminPermission::SystemView,
        );
    }

    public function scanDisk(): int
    {
        $key = 'disk-space-low';
        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());

        if ($free === false) {
            return 0;
        }

        $minimumBytes = max(268_435_456, (int) config('cms.admin_notifications.minimum_free_bytes', 1_073_741_824));
        $minimumPercent = max(1.0, min(25.0, (float) config('cms.admin_notifications.minimum_free_percent', 5.0)));
        $percent = $total !== false && $total > 0 ? ($free / $total) * 100 : null;
        $low = $free < $minimumBytes || ($percent !== null && $percent < $minimumPercent);

        if (! $low) {
            $this->notifications->resolveProblem($key);

            return 0;
        }

        $freeMegabytes = max(0, (int) floor($free / 1_048_576));
        $severity = $free < 268_435_456 || ($percent !== null && $percent < 1.0)
            ? AdminNotificationSeverity::Error
            : AdminNotificationSeverity::Warning;

        return $this->notifications->openProblem(
            new AdminNotificationData(
                type: AdminNotificationType::DiskSpaceLow,
                severity: $severity,
                titleKey: 'Free disk space is running out',
                messageKey: 'Free disk space remaining: :megabytes MB.',
                parameters: ['megabytes' => $freeMegabytes],
                routeName: 'admin.settings.system',
            ),
            $key,
            AdminPermission::SystemView,
        );
    }

    public function scanDiagnostics(): int
    {
        $key = 'diagnostic-encryption-health';

        try {
            $health = $this->encryptionHealth->inspect();
            if ($health['state'] !== 'danger') {
                $this->notifications->resolveProblem($key);

                return 0;
            }

            return $this->notifications->openProblem(
                new AdminNotificationData(
                    type: AdminNotificationType::DiagnosticProblem,
                    severity: AdminNotificationSeverity::Error,
                    titleKey: 'Diagnostics found a critical problem',
                    messageKey: 'Encrypted CMS settings cannot be read safely. Open system diagnostics for details.',
                    routeName: 'admin.settings.system',
                ),
                $key,
                AdminPermission::SystemView,
            );
        } catch (Throwable) {
            return 0;
        }
    }

    private function loginServerConfigured(LoginServer $server): bool
    {
        return trim((string) $server->driver) !== ''
            && trim((string) $server->database_host) !== ''
            && trim((string) $server->database_name) !== ''
            && trim((string) $server->database_username) !== '';
    }

    private function serverUnavailable(LoginServer|GameServer $server): bool
    {
        if ($server->monitor_status === 'offline') {
            return true;
        }

        return $server->database_checked_at !== null
            && in_array($server->database_status, ['not_configured', 'unknown'], true)
            && $server->database_error !== 'configuration_missing';
    }
}
