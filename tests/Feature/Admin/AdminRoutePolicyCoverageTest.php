<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminRouteAccessRegistry;
use App\Support\Modules\ModuleAdminAccessRegistry;
use Illuminate\Routing\Route;
use Tests\TestCase;

class AdminRoutePolicyCoverageTest extends TestCase
{
    public function test_every_named_admin_route_has_an_explicit_access_classification(): void
    {
        $registry = app(AdminRouteAccessRegistry::class);
        $moduleRegistry = app(ModuleAdminAccessRegistry::class);
        $classified = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! $route instanceof Route) {
                continue;
            }

            $routeName = $route->getName();
            if (! is_string($routeName) || ! str_starts_with($routeName, 'admin.')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            if (in_array('admin.access', $middleware, true)) {
                $this->assertTrue(
                    $registry->isRegistered($routeName)
                        || $registry->isExplicitOwnerOnly($routeName)
                        || $moduleRegistry->isRegistered($routeName),
                    "Protected admin route [{$routeName}] is missing from the access registry.",
                );
                $classified[] = $routeName;

                continue;
            }

            $this->assertTrue(
                $registry->isBypassed($routeName),
                "Admin route [{$routeName}] bypasses admin.access without an explicit registry classification.",
            );
            $classified[] = $routeName;
        }

        $this->assertNotEmpty($classified);
        $this->assertContains('admin.dashboard', $classified);
        $this->assertContains('admin.login', $classified);
        $this->assertContains('admin.settings.system', $classified);
        $this->assertContains('admin.settings.notifications', $classified);
        $this->assertContains('admin.settings.notifications.update', $classified);
    }
}
