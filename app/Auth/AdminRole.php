<?php

namespace App\Auth;

enum AdminRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Editor = 'editor';
    case Auditor = 'auditor';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Administrator => __('Administrator'),
            self::Editor => __('Editor'),
            self::Auditor => __('Auditor'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => __('Full access to the entire CMS, critical settings, owners and future modules.'),
            self::Administrator => __('Manages the CMS and its working sections, but cannot manage owners, auditors or critical settings.'),
            self::Editor => __('Works only with news, pages and content images.'),
            self::Auditor => __('Can inspect trusted administration data, diagnostics and journals without changing anything.'),
        };
    }

    /** @return list<AdminPermission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => AdminPermission::cases(),
            self::Administrator => [
                AdminPermission::DashboardView,
                AdminPermission::DashboardRefresh,
                AdminPermission::ContentView,
                AdminPermission::ContentManage,
                AdminPermission::UsersView,
                AdminPermission::UsersManage,
                AdminPermission::AppearanceView,
                AdminPermission::AppearanceManage,
                AdminPermission::ModulesView,
                AdminPermission::ServersView,
                AdminPermission::ServersManage,
                AdminPermission::MailView,
                AdminPermission::MailManage,
                AdminPermission::SettingsView,
                AdminPermission::SettingsManage,
                AdminPermission::SecurityView,
                AdminPermission::AdminPanelView,
                AdminPermission::AdministratorsView,
                AdminPermission::AdministratorsManage,
                AdminPermission::AuditView,
                AdminPermission::RewardsView,
                AdminPermission::RewardsManage,
                AdminPermission::SystemView,
                AdminPermission::QueueView,
                AdminPermission::QueueManage,
                AdminPermission::ProfileManage,
            ],
            self::Editor => [
                AdminPermission::DashboardView,
                AdminPermission::ContentView,
                AdminPermission::ContentManage,
                AdminPermission::ProfileManage,
            ],
            self::Auditor => [
                AdminPermission::DashboardView,
                AdminPermission::ContentView,
                AdminPermission::UsersView,
                AdminPermission::AppearanceView,
                AdminPermission::ModulesView,
                AdminPermission::ServersView,
                AdminPermission::MailView,
                AdminPermission::SettingsView,
                AdminPermission::SecurityView,
                AdminPermission::AdminPanelView,
                AdminPermission::AdministratorsView,
                AdminPermission::AuditView,
                AdminPermission::RewardsView,
                AdminPermission::SystemView,
                AdminPermission::SystemUpdatesView,
                AdminPermission::QueueView,
                AdminPermission::ProfileManage,
            ],
        };
    }

    public function allows(AdminPermission|string $permission): bool
    {
        $permission = $permission instanceof AdminPermission
            ? $permission
            : AdminPermission::tryFrom($permission);

        if ($permission === null) {
            return false;
        }

        return in_array($permission, $this->permissions(), true);
    }

    public function isReadOnly(): bool
    {
        return $this === self::Auditor;
    }

    public function canBeAssignedBy(self $actorRole): bool
    {
        if ($actorRole === self::Owner) {
            return true;
        }

        if ($actorRole !== self::Administrator) {
            return false;
        }

        if (in_array($this, [self::Owner, self::Auditor], true)) {
            return false;
        }

        foreach ($this->permissions() as $permission) {
            if (! $actorRole->allows($permission)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<self> */
    public static function assignableBy(self $actorRole): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $role): bool => $role->canBeAssignedBy($actorRole),
        ));
    }
}
