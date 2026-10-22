<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Services\Dashboard\DashboardAttention;
use App\Services\Infrastructure\StorageOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardStorageOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_dashboard_shows_disk_and_database_summary(): void
    {
        $administrator = Admin::factory()->administrator()->create();

        $this->actingAs($administrator, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('data-testid="dashboard-system"', false)
            ->assertSee('Диск')
            ->assertSee('База данных · SQLite')
            ->assertSee('href="'.route('admin.settings.system').'"', false);
    }

    public function test_auditor_can_view_storage_summary_without_exposing_database_credentials(): void
    {
        config()->set([
            'database.connections.sqlite.username' => 'hidden-storage-user',
            'database.connections.sqlite.password' => 'hidden-storage-password',
        ]);
        $auditor = Admin::factory()->auditor()->create();

        $this->actingAs($auditor, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('База данных · SQLite')
            ->assertDontSee('hidden-storage-user')
            ->assertDontSee('hidden-storage-password');
    }

    public function test_editor_dashboard_does_not_expose_storage_diagnostics(): void
    {
        $editor = Admin::factory()->editor()->create();

        $this->actingAs($editor, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('data-testid="dashboard-system"', false)
            ->assertDontSee('База данных · SQLite');
    }

    public function test_low_disk_space_is_listed_as_needing_attention(): void
    {
        $administrator = Admin::factory()->administrator()->create();
        $storage = app(StorageOverview::class)->collect();
        $storage['disk'] = [...$storage['disk'], 'available' => true, 'state' => 'danger', 'free' => '1,2 ГБ'];

        $attention = app(DashboardAttention::class)->collect(
            $administrator,
            ['game_servers' => [], 'login_servers' => []],
            null,
            null,
            ['stale' => false, 'failed_recent' => 0],
            null,
            $storage,
        );

        $this->assertSame([[
            'level' => 'danger',
            'title' => 'Мало места на диске: свободно 1,2 ГБ',
            'url' => route('admin.settings.system'),
        ]], $attention);
    }
}
