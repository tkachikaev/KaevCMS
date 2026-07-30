<?php

namespace Tests\Unit;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleAdminAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\TestCase;

class ModuleAdminAuthorizerTest extends TestCase
{
    public function test_authorizer_uses_the_same_registered_rules_for_routes_and_livewire_actions(): void
    {
        $registry = new ModuleAdminAccessRegistry;
        $registry->registerExact(
            moduleId: 'fixture',
            routeName: 'admin.module-pages.fixture.reply',
            viewRoles: [],
            manageRoles: [AdminRole::Owner, AdminRole::Administrator],
        );
        $authorizer = new ModuleAdminAuthorizer($registry);

        $this->assertTrue($authorizer->can(
            'admin.module-pages.fixture.reply',
            'POST',
            $this->admin(AdminRole::Administrator),
        ));
        $this->assertFalse($authorizer->can(
            'admin.module-pages.fixture.reply',
            'POST',
            $this->admin(AdminRole::Auditor),
        ));
        $this->assertTrue($authorizer->authorize(
            'admin.module-pages.fixture.reply',
            'POST',
            $this->admin(AdminRole::Owner),
        )->allowed);
    }

    public function test_authorizer_fails_closed_for_denied_and_unregistered_actions(): void
    {
        $registry = new ModuleAdminAccessRegistry;
        $registry->registerExact(
            moduleId: 'fixture',
            routeName: 'admin.module-pages.fixture.index',
            viewRoles: [AdminRole::Auditor],
            manageRoles: [AdminRole::Owner],
        );
        $authorizer = new ModuleAdminAuthorizer($registry);

        foreach ([
            ['admin.module-pages.fixture.index', 'POST'],
            ['admin.module-pages.fixture.unknown', 'GET'],
        ] as [$routeName, $method]) {
            try {
                $authorizer->authorize($routeName, $method, $this->admin(AdminRole::Auditor));
                $this->fail('Denied module action was authorized.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function admin(AdminRole $role): Admin
    {
        $admin = new Admin;
        $admin->role = $role;

        return $admin;
    }
}
