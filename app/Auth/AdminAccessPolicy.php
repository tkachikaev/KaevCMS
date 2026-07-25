<?php

namespace App\Auth;

use Illuminate\Http\Request;

final class AdminAccessPolicy
{
    public function decide(Request $request): AdminAccessDecision
    {
        $name = (string) ($request->route()?->getName() ?? '');
        $method = strtoupper($request->method());
        $isRead = in_array($method, ['GET', 'HEAD'], true);

        if ($name === 'admin.dashboard' || $name === 'admin.server-monitor.status') {
            return new AdminAccessDecision(AdminPermission::DashboardView);
        }

        if ($name === 'admin.server-monitor.refresh') {
            return new AdminAccessDecision(AdminPermission::DashboardRefresh);
        }

        if ($name === 'admin.logout') {
            return new AdminAccessDecision(AdminPermission::ProfileManage);
        }

        if (str_starts_with($name, 'admin.account.')) {
            return new AdminAccessDecision(AdminPermission::ProfileManage);
        }

        if (str_starts_with($name, 'admin.news.') || str_starts_with($name, 'admin.pages.')) {
            return new AdminAccessDecision(AdminPermission::ContentManage);
        }

        if (str_starts_with($name, 'admin.themes.') || str_starts_with($name, 'admin.account-themes.')) {
            return $isRead
                ? new AdminAccessDecision(AdminPermission::AppearanceView, AdminPermission::AppearanceManage)
                : new AdminAccessDecision(AdminPermission::AppearanceManage);
        }

        if (str_starts_with($name, 'admin.modules.') || str_starts_with($name, 'admin.module-pages.')) {
            return $isRead
                ? new AdminAccessDecision(AdminPermission::ModulesView, AdminPermission::ModulesManage)
                : new AdminAccessDecision(AdminPermission::ModulesManage);
        }

        if (str_starts_with($name, 'admin.users.')) {
            return new AdminAccessDecision(AdminPermission::UsersManage);
        }

        if (str_starts_with($name, 'admin.administrators.')) {
            return new AdminAccessDecision(AdminPermission::AdministratorsManage);
        }

        if (str_starts_with($name, 'admin.logs.')) {
            return new AdminAccessDecision(AdminPermission::AuditView);
        }

        if (str_starts_with($name, 'admin.rewards.')) {
            return $isRead
                ? new AdminAccessDecision(AdminPermission::AuditView, AdminPermission::RewardsManage)
                : new AdminAccessDecision(AdminPermission::RewardsManage);
        }

        if (str_starts_with($name, 'admin.settings.game-server') || str_starts_with($name, 'admin.settings.login-server')) {
            return $isRead
                ? new AdminAccessDecision(AdminPermission::ServersView, AdminPermission::ServersManage)
                : new AdminAccessDecision(AdminPermission::ServersManage);
        }

        if (str_starts_with($name, 'admin.settings.mail')) {
            return $isRead
                ? new AdminAccessDecision(AdminPermission::MailView, AdminPermission::MailManage)
                : new AdminAccessDecision(AdminPermission::MailManage);
        }

        if (str_starts_with($name, 'admin.settings.security')) {
            return $isRead
                ? new AdminAccessDecision(AdminPermission::SettingsView, AdminPermission::SecurityManage)
                : new AdminAccessDecision(AdminPermission::SecurityManage);
        }

        if ($name === 'admin.settings.admin-panel.admin-path.update') {
            return new AdminAccessDecision(AdminPermission::AdminPathManage);
        }

        if ($name === 'admin.settings.admin-panel.monitoring.update') {
            return new AdminAccessDecision(AdminPermission::SettingsManage);
        }

        if (str_starts_with($name, 'admin.settings.admin-panel')) {
            return $isRead
                ? new AdminAccessDecision(AdminPermission::SettingsView, AdminPermission::SettingsManage)
                : new AdminAccessDecision(AdminPermission::SettingsManage);
        }

        if (str_starts_with($name, 'admin.settings.system.updates')) {
            return $isRead
                ? new AdminAccessDecision(AdminPermission::SystemView)
                : new AdminAccessDecision(AdminPermission::SettingsManage);
        }

        if (str_starts_with($name, 'admin.settings.system.queue')) {
            return $isRead
                ? new AdminAccessDecision(AdminPermission::SystemView)
                : new AdminAccessDecision(AdminPermission::SettingsManage);
        }

        if ($name === 'admin.settings.system') {
            return new AdminAccessDecision(AdminPermission::SystemView);
        }

        if (str_starts_with($name, 'admin.settings.')) {
            return $isRead
                ? new AdminAccessDecision(AdminPermission::SettingsView, AdminPermission::SettingsManage)
                : new AdminAccessDecision(AdminPermission::SettingsManage);
        }

        return new AdminAccessDecision(AdminPermission::AdminPathManage);
    }
}
