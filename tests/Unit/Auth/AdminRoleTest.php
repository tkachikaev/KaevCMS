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

    public function test_owner_administrator_editor_and_auditor_roles_are_available(): void
    {
        $this->assertSame(
            ['owner', 'administrator', 'editor', 'auditor'],
            array_map(static fn (AdminRole $role): string => $role->value, AdminRole::cases()),
        );
    }

    public function test_only_owner_can_assign_trusted_global_roles(): void
    {
        $this->assertContains(AdminRole::Owner, AdminRole::assignableBy(AdminRole::Owner));
        $this->assertContains(AdminRole::Auditor, AdminRole::assignableBy(AdminRole::Owner));

        foreach ([AdminRole::Administrator, AdminRole::Editor, AdminRole::Auditor] as $role) {
            $this->assertNotContains(AdminRole::Owner, AdminRole::assignableBy($role));
            $this->assertNotContains(AdminRole::Auditor, AdminRole::assignableBy($role));
        }

        $this->assertSame(
            [AdminRole::Administrator, AdminRole::Editor],
            AdminRole::assignableBy(AdminRole::Administrator),
        );
        $this->assertSame([], AdminRole::assignableBy(AdminRole::Editor));
        $this->assertSame([], AdminRole::assignableBy(AdminRole::Auditor));
    }

    public function test_non_owner_cannot_assign_a_role_with_permissions_they_do_not_have(): void
    {
        foreach (AdminRole::cases() as $actorRole) {
            if ($actorRole === AdminRole::Owner) {
                continue;
            }

            foreach (AdminRole::assignableBy($actorRole) as $targetRole) {
                foreach ($targetRole->permissions() as $permission) {
                    $this->assertTrue(
                        $actorRole->allows($permission),
                        "{$actorRole->value} cannot assign {$targetRole->value} because it lacks {$permission->value}.",
                    );
                }
            }
        }
    }

    public function test_only_auditor_is_globally_read_only(): void
    {
        $this->assertTrue(AdminRole::Auditor->isReadOnly());
        $this->assertFalse(AdminRole::Owner->isReadOnly());
        $this->assertFalse(AdminRole::Administrator->isReadOnly());
        $this->assertFalse(AdminRole::Editor->isReadOnly());
    }

    /** @return array<string, array{AdminRole, list<AdminPermission>}> */
    public static function rolePermissions(): array
    {
        return [
            'owner' => [AdminRole::Owner, AdminPermission::cases()],
            'administrator' => [AdminRole::Administrator, [
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
            ]],
            'editor' => [AdminRole::Editor, [
                AdminPermission::DashboardView,
                AdminPermission::ContentView,
                AdminPermission::ContentManage,
                AdminPermission::ProfileManage,
            ]],
            'auditor' => [AdminRole::Auditor, [
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
            ]],
        ];
    }
}
