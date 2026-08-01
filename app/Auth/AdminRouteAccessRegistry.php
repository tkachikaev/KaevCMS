<?php

namespace App\Auth;

final class AdminRouteAccessRegistry
{
    /** @var list<AdminRouteAccessRule> */
    private array $rules;

    /** @var list<string> */
    private array $bypassedRoutes;

    /** @var list<string> */
    private array $ownerOnlyRoutes;

    public function __construct()
    {
        $this->rules = [
            $this->exact('admin.dashboard', AdminPermission::DashboardView),
            $this->exact('admin.server-monitor.status', AdminPermission::DashboardView),
            $this->exact('admin.server-monitor.refresh', AdminPermission::DashboardRefresh),
            $this->exact('admin.logout', AdminPermission::ProfileManage),
            $this->exact('admin.settings.admin-panel.admin-path.update', AdminPermission::AdminPathManage),
            $this->exact('admin.settings.admin-panel.monitoring.update', AdminPermission::SettingsManage),
            $this->exact(
                'admin.settings.notifications',
                AdminPermission::SettingsView,
                AdminPermission::SettingsManage,
            ),
            $this->exact(
                'admin.settings.notifications.update',
                AdminPermission::SettingsView,
                AdminPermission::SettingsManage,
            ),
            $this->exact(
                'admin.settings.system',
                AdminPermission::SystemView,
                AdminPermission::SettingsManage,
            ),
            $this->exact(
                'admin.settings.system.diagnostics.download',
                AdminPermission::SystemView,
            ),
            $this->exact(
                'admin.settings.system.external-databases.refresh',
                AdminPermission::SettingsManage,
            ),
            $this->prefix('admin.account.', AdminPermission::ProfileManage),
            $this->prefix(
                'admin.news.',
                AdminPermission::ContentView,
                AdminPermission::ContentManage,
            ),
            $this->prefix(
                'admin.pages.',
                AdminPermission::ContentView,
                AdminPermission::ContentManage,
            ),
            $this->prefix(
                'admin.themes.',
                AdminPermission::AppearanceView,
                AdminPermission::AppearanceManage,
            ),
            $this->prefix(
                'admin.account-themes.',
                AdminPermission::AppearanceView,
                AdminPermission::AppearanceManage,
            ),
            $this->prefix(
                'admin.modules.',
                AdminPermission::ModulesView,
                AdminPermission::ModulesManage,
            ),
            $this->exact(
                'admin.module-pages.promo-codes.activations',
                AdminPermission::RewardsView,
            ),
            $this->exact(
                'admin.module-pages.daily-rewards.claims',
                AdminPermission::RewardsView,
            ),
            $this->prefix(
                'admin.module-pages.',
                AdminPermission::ModulesView,
                AdminPermission::ModulesManage,
            ),
            $this->prefix(
                'admin.users.',
                AdminPermission::UsersView,
                AdminPermission::UsersManage,
            ),
            $this->prefix(
                'admin.administrators.',
                AdminPermission::AdministratorsView,
                AdminPermission::AdministratorsManage,
            ),
            $this->prefix('admin.logs.', AdminPermission::AuditView),
            $this->prefix(
                'admin.rewards.',
                AdminPermission::RewardsView,
                AdminPermission::RewardsManage,
            ),
            $this->prefix(
                'admin.settings.game-server',
                AdminPermission::ServersView,
                AdminPermission::ServersManage,
            ),
            $this->prefix(
                'admin.settings.login-server',
                AdminPermission::ServersView,
                AdminPermission::ServersManage,
            ),
            $this->prefix(
                'admin.settings.mail',
                AdminPermission::MailView,
                AdminPermission::MailManage,
            ),
            $this->prefix(
                'admin.settings.security',
                AdminPermission::SecurityView,
                AdminPermission::SecurityManage,
            ),
            $this->prefix(
                'admin.settings.admin-panel',
                AdminPermission::AdminPanelView,
                AdminPermission::SettingsManage,
            ),
            $this->prefix(
                'admin.settings.system.updates',
                AdminPermission::SystemUpdatesView,
                AdminPermission::SystemUpdatesManage,
            ),
            $this->prefix(
                'admin.settings.system.queue',
                AdminPermission::QueueView,
                AdminPermission::QueueManage,
            ),
            $this->prefix(
                'admin.settings.',
                AdminPermission::SettingsView,
                AdminPermission::SettingsManage,
            ),
        ];

        $this->bypassedRoutes = [
            'admin.language.switch',
            'admin.login',
            'admin.login.store',
            'admin.two-factor.challenge',
            'admin.two-factor.challenge.store',
            'admin.two-factor.challenge.cancel',
        ];

        $this->ownerOnlyRoutes = [];
    }

    public function decision(string $routeName, string $method): ?AdminAccessDecision
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($routeName)) {
                return $rule->decision($method);
            }
        }

        return null;
    }

    public function isRegistered(string $routeName): bool
    {
        return $this->decision($routeName, 'GET') !== null;
    }

    public function isBypassed(string $routeName): bool
    {
        return in_array($routeName, $this->bypassedRoutes, true);
    }

    public function isExplicitOwnerOnly(string $routeName): bool
    {
        return in_array($routeName, $this->ownerOnlyRoutes, true);
    }

    /** @return list<AdminRouteAccessRule> */
    public function rules(): array
    {
        return $this->rules;
    }

    private function exact(
        string $routeName,
        AdminPermission $permission,
        ?AdminPermission $managePermission = null,
        bool $markReadOnly = true,
    ): AdminRouteAccessRule {
        return new AdminRouteAccessRule(
            $routeName,
            $permission,
            $managePermission,
            markReadOnly: $markReadOnly,
        );
    }

    private function prefix(
        string $routePrefix,
        AdminPermission $permission,
        ?AdminPermission $managePermission = null,
        bool $markReadOnly = true,
    ): AdminRouteAccessRule {
        return new AdminRouteAccessRule(
            $routePrefix,
            $permission,
            $managePermission,
            prefix: true,
            markReadOnly: $markReadOnly,
        );
    }
}
