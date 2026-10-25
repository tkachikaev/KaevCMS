<?php

namespace Tests\Feature\UniversalDrivers;

use App\Auth\AdminRole;
use App\Contracts\ExternalDatabaseConnectionTester;
use App\Livewire\Admin\LoginServerManager;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\LoginServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ApplyJsonProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_applying_a_json_profile_to_a_login_server_is_audited_instead_of_crashing(): void
    {
        $this->app->instance(ExternalDatabaseConnectionTester::class, new class implements ExternalDatabaseConnectionTester
        {
            public function test(array $connection, array $requirements, bool $driverReady): array
            {
                return ['connected' => true, 'compatible' => true, 'server_version' => 'fixture', 'error' => null, 'error_class' => null, 'latency_ms' => 1, 'checks' => []];
            }
        });
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $server = LoginServer::factory()->create(['driver' => 'rusacis']);

        Livewire::test(LoginServerManager::class)
            ->call('applyJsonProfile', $server->id)
            ->assertHasNoErrors();

        $this->assertTrue(AuditLog::query()->where('action', 'universal_driver_applied')->exists());
    }
}
