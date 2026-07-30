<?php

namespace Tests\Unit;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Support\Modules\ModuleAdminAccessLevel;
use App\Support\Modules\ModuleAdminAccessRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ModuleAdminAccessRegistryTest extends TestCase
{
    public function test_module_rules_distinguish_manage_read_and_denied_roles(): void
    {
        $registry = new ModuleAdminAccessRegistry;
        $registry->registerPrefix(
            moduleId: 'fixture',
            routePrefix: 'admin.module-pages.fixture.',
            viewRoles: [AdminRole::Auditor],
            manageRoles: [AdminRole::Owner, AdminRole::Administrator],
            additionalAccess: static fn (Admin $admin): ModuleAdminAccessLevel => $admin->role === AdminRole::Editor
                ? ModuleAdminAccessLevel::Manage
                : ModuleAdminAccessLevel::Denied,
        );

        $owner = $this->admin(AdminRole::Owner);
        $editor = $this->admin(AdminRole::Editor);
        $auditor = $this->admin(AdminRole::Auditor);

        $this->assertSame([true, false], $this->decision($registry, $owner, 'POST'));
        $this->assertSame([true, false], $this->decision($registry, $editor, 'POST'));
        $this->assertSame([true, true], $this->decision($registry, $auditor, 'GET'));
        $this->assertSame([false, true], $this->decision($registry, $auditor, 'POST'));
    }

    public function test_exact_rule_wins_over_a_broader_prefix_regardless_of_registration_order(): void
    {
        $registry = new ModuleAdminAccessRegistry;
        $registry->registerPrefix(
            moduleId: 'fixture',
            routePrefix: 'admin.module-pages.fixture.',
            viewRoles: [AdminRole::Auditor],
            manageRoles: [AdminRole::Owner, AdminRole::Administrator],
        );
        $registry->registerExact(
            moduleId: 'fixture',
            routeName: 'admin.module-pages.fixture.settings',
            viewRoles: [],
            manageRoles: [AdminRole::Owner],
        );

        $administrator = $this->admin(AdminRole::Administrator);
        $owner = $this->admin(AdminRole::Owner);

        $this->assertFalse($registry->decision('admin.module-pages.fixture.settings', 'GET', $administrator)?->allowed);
        $this->assertTrue($registry->decision('admin.module-pages.fixture.settings', 'POST', $owner)?->allowed);
    }


    public function test_module_role_lists_are_validated_at_runtime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Module access roles must use AdminRole values.');

        /** @var list<AdminRole> $invalidRoles */
        $invalidRoles = [AdminRole::Owner, 'administrator'];

        (new ModuleAdminAccessRegistry)->registerExact(
            moduleId: 'fixture',
            routeName: 'admin.module-pages.fixture.index',
            viewRoles: $invalidRoles,
            manageRoles: [AdminRole::Owner],
        );
    }

    public function test_module_cannot_register_routes_outside_its_namespace(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ModuleAdminAccessRegistry)->registerExact(
            moduleId: 'fixture',
            routeName: 'admin.settings.general',
            viewRoles: [],
            manageRoles: [AdminRole::Owner],
        );
    }

    /** @return array{bool,bool} */
    private function decision(ModuleAdminAccessRegistry $registry, Admin $admin, string $method): array
    {
        $decision = $registry->decision('admin.module-pages.fixture.index', $method, $admin);
        $this->assertNotNull($decision);

        return [$decision->allowed, $decision->readOnly];
    }

    private function admin(AdminRole $role): Admin
    {
        $admin = new Admin;
        $admin->role = $role;

        return $admin;
    }
}
