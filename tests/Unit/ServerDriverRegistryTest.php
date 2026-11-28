<?php

namespace Tests\Unit;

use App\Services\Servers\ServerDriverRegistry;
use Tests\TestCase;

class ServerDriverRegistryTest extends TestCase
{
    public function test_existing_login_and_game_driver_identifiers_remain_selectable(): void
    {
        $registry = app(ServerDriverRegistry::class);

        foreach (['l2j_mobius', 'l2j_mobius_legacy', 'lucera2', 'rusacis'] as $id) {
            $this->assertContains($id, $registry->loginDriverKeys());
        }
        foreach (['l2j_mobius', 'lucera2', 'rusacis'] as $id) {
            $this->assertContains($id, $registry->gameDriverKeys());
        }
    }

    public function test_bundled_login_drivers_require_account_credentials_but_not_optional_metadata(): void
    {
        $registry = app(ServerDriverRegistry::class);
        foreach (['l2j_mobius', 'l2j_mobius_legacy', 'lucera2', 'rusacis'] as $id) {
            $driver = $registry->loginDriver($id);
            $this->assertTrue($driver['ready']);
            $this->assertSame('accounts', $driver['requirements'][0]['table']);
            $this->assertContains('login', $driver['requirements'][0]['columns']);
            $this->assertContains('password', $driver['requirements'][0]['columns']);
            $this->assertNotContains('last_active', $driver['requirements'][0]['columns']);
            $this->assertNotContains('lastactive', $driver['requirements'][0]['columns']);
        }
    }
}
