<?php

namespace Tests\Feature\Admin;

use App\Models\SystemHeartbeat;
use App\Services\CmsSettings;
use App\Services\Infrastructure\RuntimeDiagnostics;
use App\Services\MailSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class RuntimeDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_heartbeat_command_is_registered_every_minute(): void
    {
        $event = collect(Schedule::events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'kaevcms:scheduler-heartbeat'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_scheduler_command_records_a_real_heartbeat(): void
    {
        $this->artisan('kaevcms:scheduler-heartbeat')->assertSuccessful();

        $heartbeat = SystemHeartbeat::query()->find(RuntimeDiagnostics::SCHEDULER_HEARTBEAT);

        $this->assertNotNull($heartbeat);
        $this->assertTrue($heartbeat->last_seen_at->greaterThan(now()->subMinute()));
    }

    public function test_only_database_queue_processing_records_worker_activity(): void
    {
        Queue::connection('sync')->push(new RuntimeDiagnosticsProbeJob);

        foreach ([
            RuntimeDiagnostics::QUEUE_WORKER_HEARTBEAT,
            RuntimeDiagnostics::QUEUE_WORKER_SUCCEEDED,
            RuntimeDiagnostics::QUEUE_WORKER_FAILED,
        ] as $heartbeatKey) {
            $this->assertDatabaseMissing('system_heartbeats', ['key' => $heartbeatKey]);
        }

        config()->set('queue.connections.database', ['driver' => 'sync']);
        Queue::connection('database')->push(new RuntimeDiagnosticsProbeJob);

        $heartbeat = SystemHeartbeat::query()->find(RuntimeDiagnostics::QUEUE_WORKER_HEARTBEAT);

        $this->assertNotNull($heartbeat);
        $this->assertSame('database', $heartbeat->metadata['connection'] ?? null);
    }

    public function test_database_queue_reports_stale_jobs_and_failures(): void
    {
        app(CmsSettings::class)->set(MailSettings::KEY_DELIVERY_MODE, MailSettings::MODE_DATABASE);

        SystemHeartbeat::query()->create([
            'key' => RuntimeDiagnostics::SCHEDULER_HEARTBEAT,
            'last_seen_at' => now()->subMinutes(10),
        ]);
        SystemHeartbeat::query()->create([
            'key' => RuntimeDiagnostics::QUEUE_WORKER_HEARTBEAT,
            'last_seen_at' => now()->subMinutes(10),
        ]);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(5)->getTimestamp(),
            'created_at' => now()->subMinutes(5)->getTimestamp(),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'runtime-diagnostics-failed-job',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Test failure',
            'failed_at' => now()->subMinute(),
        ]);

        $overview = app(RuntimeDiagnostics::class)->overview();

        $this->assertSame('danger', $overview['overall_state']);
        $this->assertSame('danger', $overview['scheduler']['state']);
        $this->assertSame('danger', $overview['queue']['state']);
        $this->assertSame(1, $overview['jobs']['pending']);
        $this->assertSame(1, $overview['jobs']['failed']);
        $this->assertContains(
            'Задания очереди базы данных ожидают более двух минут без недавней активности обработчика.',
            $overview['warnings'],
        );
    }

    public function test_database_queue_is_ready_when_scheduler_is_fresh_and_no_jobs_are_waiting(): void
    {
        app(CmsSettings::class)->set(MailSettings::KEY_DELIVERY_MODE, MailSettings::MODE_DATABASE);
        app(RuntimeDiagnostics::class)->recordSchedulerHeartbeat();

        $overview = app(RuntimeDiagnostics::class)->overview();

        $this->assertSame('success', $overview['scheduler']['state']);
        $this->assertSame('success', $overview['queue']['state']);
        $this->assertTrue($overview['queue']['requires_worker']);
        $this->assertSame([], $overview['warnings']);
    }

    public function test_doctor_checks_scheduler_and_database_queue_registration(): void
    {
        $doctor = file_get_contents(base_path('deployment/windows/doctor.ps1'));

        $this->assertIsString($doctor);
        $this->assertStringContainsString('kaevcms:scheduler-heartbeat', $doctor);
        $this->assertStringContainsString('kaevcms:queue-drain', $doctor);
        $this->assertStringContainsString('kaevcms:queue-clean', $doctor);
    }

    public function test_sync_mail_mode_does_not_require_a_queue_worker(): void
    {
        $overview = app(RuntimeDiagnostics::class)->overview();

        $this->assertFalse($overview['queue']['requires_worker']);
        $this->assertSame('success', $overview['queue']['state']);
        $this->assertSame(MailSettings::MODE_SYNC, $overview['queue']['mode']);
    }
}

final class RuntimeDiagnosticsProbeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        // The queue event itself is the behaviour under test.
    }
}
