<?php

namespace Tests\Unit\Auth;

use App\Auth\AdminPermission;
use App\Auth\AdminRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminRoleTest extends TestCase
{
    /**
     * @param  list<AdminPermission>  $expected
     */
    #[DataProvider('rolePermissions')]
    public function test_each_role_has_the_expected_permissions(AdminRole $role, array $expected): void
    {
        $this->assertSame($expected, $role->permissions());

        foreach (AdminPermission::cases() as $permission) {
            $this->assertSame(
                in_array($permission, $expected, true),
                $role->allows($permission),
                "Unexpected {$permission->value} permission for {$role->value}.",
            );
        }
    }

    public function test_only_owner_administrator_and_editor_roles_are_available(): void
    {
        $this->assertSame(
            ['owner', 'administrator', 'editor'],
            array_map(static fn (AdminRole $role): string => $role->value, AdminRole::cases()),
        );
    }

    public function test_only_owner_can_assign_owner_role(): void
    {
        $this->assertContains(AdminRole::Owner, AdminRole::assignableBy(AdminRole::Owner));

        foreach ([AdminRole::Administrator, AdminRole::Editor] as $role) {
            $this->assertNotContains(AdminRole::Owner, AdminRole::assignableBy($role));
        }
    }

    /** @return array<string, array{AdminRole, list<AdminPermission>}> */
    public static function rolePermissions(): array
    {
        return [
            'owner' => [AdminRole::Owner, AdminPermission::cases()],
            'administrator' => [AdminRole::Administrator, [
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
            ]],
            'editor' => [AdminRole::Editor, [
                AdminPermission::DashboardView,
                AdminPermission::ContentManage,
                AdminPermission::ProfileManage,
            ]],
        ];
    }
}
