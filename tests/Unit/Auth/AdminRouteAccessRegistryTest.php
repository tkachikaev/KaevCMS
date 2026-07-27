<?php

namespace Tests\Unit\Auth;

use App\Auth\AdminPermission;
use App\Auth\AdminRouteAccessRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminRouteAccessRegistryTest extends TestCase
{
    #[DataProvider('decisions')]
    public function test_registry_resolves_route_permissions(
        string $routeName,
        string $method,
        AdminPermission $permission,
        ?AdminPermission $managePermission,
    ): void {
        $decision = (new AdminRouteAccessRegistry)->decision($routeName, $method);

        $this->assertNotNull($decision);
        $this->assertSame($permission, $decision->permission);
        $this->assertSame($managePermission, $decision->managePermission);
    }

    public function test_unknown_route_is_not_silently_registered(): void
    {
        $registry = new AdminRouteAccessRegistry;

        $this->assertNull($registry->decision('admin.future.unclassified', 'GET'));
        $this->assertFalse($registry->isRegistered('admin.future.unclassified'));
        $this->assertFalse($registry->isExplicitOwnerOnly('admin.future.unclassified'));
    }

    public function test_authentication_routes_are_explicitly_bypassed(): void
    {
        $registry = new AdminRouteAccessRegistry;

        foreach ([
            'admin.language.switch',
            'admin.login',
            'admin.login.store',
            'admin.two-factor.challenge',
            'admin.two-factor.challenge.store',
            'admin.two-factor.challenge.cancel',
        ] as $routeName) {
            $this->assertTrue($registry->isBypassed($routeName));
        }
    }

    /** @return array<string, array{string, string, AdminPermission, AdminPermission|null}> */
    public static function decisions(): array
    {
        return [
            'dashboard' => [
                'admin.dashboard',
                'GET',
                AdminPermission::DashboardView,
                null,
            ],
            'module catalogue read-only' => [
                'admin.modules.index',
                'GET',
                AdminPermission::ModulesView,
                AdminPermission::ModulesManage,
            ],
            'module catalogue manage' => [
                'admin.modules.enable',
                'POST',
                AdminPermission::ModulesManage,
                null,
            ],
            'bundled module edit read-only' => [
                'admin.module-pages.daily-rewards.edit',
                'GET',
                AdminPermission::ModulesView,
                AdminPermission::ModulesManage,
            ],
            'bundled module update' => [
                'admin.module-pages.daily-rewards.update',
                'PUT',
                AdminPermission::ModulesManage,
                null,
            ],
            'appearance read-only' => [
                'admin.account-themes.index',
                'GET',
                AdminPermission::AppearanceView,
                AdminPermission::AppearanceManage,
            ],
            'security read-only' => [
                'admin.settings.security',
                'GET',
                AdminPermission::SettingsView,
                AdminPermission::SecurityManage,
            ],
            'security update' => [
                'admin.settings.security.update',
                'PUT',
                AdminPermission::SecurityManage,
                null,
            ],
            'system updates view preserves existing non-read-only decision' => [
                'admin.settings.system.updates.index',
                'GET',
                AdminPermission::SystemView,
                null,
            ],
            'system updates manage' => [
                'admin.settings.system.updates.apply',
                'POST',
                AdminPermission::SettingsManage,
                null,
            ],
            'admin path owner only' => [
                'admin.settings.admin-panel.admin-path.update',
                'PUT',
                AdminPermission::AdminPathManage,
                null,
            ],
        ];
    }
}
