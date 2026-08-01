<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardStorageOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_dashboard_shows_disk_and_database_storage_overview(): void
    {
        $administrator = Admin::factory()->administrator()->create();

        $this->actingAs($administrator, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Хранилище')
            ->assertSee('Диск сервера')
            ->assertSee('База данных KaevCMS')
            ->assertSee('Общий размер')
            ->assertSee('Данные')
            ->assertSee('Индексы')
            ->assertSee('Таблицы')
            ->assertSee('data-testid="dashboard-storage-card"', false)
            ->assertSee('role="progressbar"', false);
    }

    public function test_auditor_can_view_storage_overview_without_exposing_database_credentials(): void
    {
        config()->set([
            'database.connections.sqlite.username' => 'hidden-storage-user',
            'database.connections.sqlite.password' => 'hidden-storage-password',
        ]);
        $auditor = Admin::factory()->auditor()->create();

        $this->actingAs($auditor, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Хранилище')
            ->assertSee('База данных KaevCMS')
            ->assertDontSee('hidden-storage-user')
            ->assertDontSee('hidden-storage-password');
    }

    public function test_editor_dashboard_does_not_expose_storage_diagnostics(): void
    {
        $editor = Admin::factory()->editor()->create();

        $this->actingAs($editor, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('data-testid="dashboard-storage-card"', false)
            ->assertDontSee('База данных KaevCMS');
    }

    public function test_dashboard_storage_uses_existing_card_and_status_components(): void
    {
        $view = file_get_contents(resource_path('views/admin/dashboard.blade.php'));
        $css = file_get_contents(public_path('assets/admin/css/infrastructure.css'));

        $this->assertIsString($view);
        $this->assertIsString($css);
        $this->assertStringContainsString('admin-data-card dashboard-monitor-card dashboard-storage-card', $view);
        $this->assertStringContainsString('status-badge status-badge-', $view);
        $this->assertStringContainsString('dashboard-storage-progress', $css);
        $this->assertStringContainsString('<progress', $view);
        $this->assertStringContainsString('value="{{ $diskPercentCss }}"', $view);
        $this->assertStringNotContainsString('style="width: {{ $diskPercentCss }}%;"', $view);
        $this->assertStringContainsString('::-webkit-progress-value', $css);
        $this->assertStringContainsString('::-moz-progress-bar', $css);
        $this->assertStringNotContainsString('<canvas', $view);
        $this->assertStringNotContainsString('<svg', $view);
    }
}
