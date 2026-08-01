<?php

namespace App\Support\Notifications;

enum AdminNotificationType: string
{
    case SupportTicketCreated = 'support_ticket_created';
    case SupportTicketPlayerReply = 'support_ticket_player_reply';
    case ModuleUpdateAvailable = 'module_update_available';
    case ModuleMigrationPending = 'module_migration_pending';
    case CoreMigrationPending = 'core_migration_pending';
    case SystemUpdateFailed = 'system_update_failed';
    case QueueProblem = 'queue_problem';
    case ServerUnavailable = 'server_unavailable';
    case DiskSpaceLow = 'disk_space_low';
    case InstallerPresent = 'installer_present';
    case DiagnosticProblem = 'diagnostic_problem';
    case ImportantOperationSucceeded = 'important_operation_succeeded';

    /** @return list<string> */
    public function allowedRoutes(): array
    {
        return match ($this) {
            self::SupportTicketCreated,
            self::SupportTicketPlayerReply => ['admin.module-pages.support-tickets.show'],
            self::ModuleUpdateAvailable,
            self::ModuleMigrationPending => ['admin.modules.index'],
            self::CoreMigrationPending,
            self::DiskSpaceLow,
            self::InstallerPresent,
            self::DiagnosticProblem => ['admin.settings.system'],
            self::SystemUpdateFailed => [
                'admin.settings.system.updates.index',
                'admin.settings.system.updates.show',
            ],
            self::QueueProblem => ['admin.settings.system.queue'],
            self::ServerUnavailable => [
                'admin.settings.game-server',
                'admin.settings.login-server',
            ],
            self::ImportantOperationSucceeded => [
                'admin.dashboard',
                'admin.settings.system',
                'admin.settings.system.updates.index',
                'admin.settings.system.updates.show',
            ],
        };
    }

    public function allowsRoute(?string $routeName): bool
    {
        return $routeName === null || in_array($routeName, $this->allowedRoutes(), true);
    }
}
