<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\SystemHeartbeat;
use App\Services\Infrastructure\RuntimeDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardRuntimeDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_dashboard_shows_scheduler_queue_and_general_job_counters(): void
    {
        $administrator = Admin::factory()->administrator()->create();

        SystemHeartbeat::query()->create([
            'key' => RuntimeDiagnostics::SCHEDULER_HEARTBEAT,
            'last_seen_at' => now(),
        ]);

        DB::table('jobs')->insert([
            'queue' => 'mail',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        $this->actingAs($administrator, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('data-testid="dashboard-system"', false)
            ->assertSee('Планировщик Laravel')
            ->assertSee('Обработка очереди')
            ->assertSee('href="'.route('admin.settings.system').'"', false);
    }

    public function test_failed_background_jobs_are_listed_as_needing_attention(): void
    {
        $administrator = Admin::factory()->administrator()->create();
        SystemHeartbeat::query()->create([
            'key' => RuntimeDiagnostics::SCHEDULER_HEARTBEAT,
            'last_seen_at' => now(),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'dashboard-failed-job',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException',
            'failed_at' => now(),
        ]);

        $this->actingAs($administrator, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('1 фоновая задача завершилась с ошибкой')
            ->assertSee('href="'.route('admin.settings.system.queue').'"', false);
    }

    public function test_editor_dashboard_does_not_expose_runtime_diagnostics(): void
    {
        $editor = Admin::factory()->editor()->create();

        $this->actingAs($editor, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('Планировщик Laravel')
            ->assertDontSee('Обработка очереди');
    }
}
