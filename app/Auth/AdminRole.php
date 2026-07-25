<?php

namespace App\Auth;

enum AdminRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Editor = 'editor';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Administrator => __('Administrator'),
            self::Editor => __('Editor'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => __('Full access to the entire CMS, critical settings, owners and future modules.'),
            self::Administrator => __('Manages the CMS and its working sections, but cannot manage owners or critical settings.'),
            self::Editor => __('Works only with news, pages and content images.'),
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
                AdminPermission::ContentManage,
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
                AdminPermission::AdministratorsManage,
                AdminPermission::AuditView,
                AdminPermission::RewardsManage,
                AdminPermission::SystemView,
                AdminPermission::ProfileManage,
            ],
            self::Editor => [
                AdminPermission::DashboardView,
                AdminPermission::ContentManage,
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

        foreach ($this->permissions() as $allowedPermission) {
            if ($allowedPermission === $permission) {
                return true;
            }
        }

        return false;
    }

    /** @return list<self> */
    public static function assignableBy(self $actorRole): array
    {
        return $actorRole === self::Owner
            ? self::cases()
            : [self::Administrator, self::Editor];
    }
}
