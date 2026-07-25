<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\MailDelivery;
use App\Models\SystemHeartbeat;
use App\Services\Infrastructure\QueueMaintenance;
use App\Services\Infrastructure\QueueWorkerRunner;
use App\Services\Infrastructure\RuntimeDiagnostics;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class QueueInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_drains_every_database_queue_and_cleans_service_data(): void
    {
        $events = collect(Schedule::events());
        $drain = $events->first(fn (Event $event): bool => str_contains((string) $event->command, 'kaevcms:queue-drain'));
        $cleanup = $events->first(fn (Event $event): bool => str_contains((string) $event->command, 'kaevcms:queue-clean'));

        $this->assertNotNull($drain);
        $this->assertSame('* * * * *', $drain->expression);
        $this->assertNotNull($cleanup);
        $this->assertSame('45 3 * * *', $cleanup->expression);

        $consoleRoutes = file_get_contents(base_path('routes/console.php'));
        $this->assertIsString($consoleRoutes);
        $this->assertStringNotContainsString('--queue=mail-probe,mail', $consoleRoutes);
        $this->assertStringNotContainsString("DB::table('jobs')", $consoleRoutes);
    }

    public function test_scheduler_drain_processes_every_present_queue_in_priority_order(): void
    {
        $this->insertPendingJob('module-events', now()->getTimestamp());
        $this->insertPendingJob('mail', now()->getTimestamp());
        $this->insertPendingJob('mail-probe', now()->getTimestamp());

        $processedQueues = [];
        $this->mock(QueueWorkerRunner::class, function (MockInterface $mock) use (&$processedQueues): void {
            $mock->shouldReceive('run')
                ->times(3)
                ->withArgs(function (string $queue, int $maxTime, int $maxJobs, int $tries) use (&$processedQueues): bool {
                    $processedQueues[] = $queue;

                    return $maxTime >= 1 && $maxJobs >= 1 && $tries === 3;
                })
                ->andReturn([
                    'exit_code' => 0,
                    'output' => '',
                ]);
        });

        $this->artisan('kaevcms:queue-drain')->assertSuccessful();
        $this->assertSame(['mail-probe', 'mail', 'module-events'], $processedQueues);
        $this->assertFalse((bool) config('cms.queue.scheduler_drain_active'));
    }

    public function test_scheduler_drain_activity_does_not_create_a_permanent_worker_restart_warning(): void
    {
        config()->set('cms.queue.scheduler_drain_active', true);
        $diagnostics = app(RuntimeDiagnostics::class);
        $diagnostics->recordQueueSucceeded('database', 'mail', 1, 'MailJob');
        config()->set('cms.queue.scheduler_drain_active', false);

        $this->assertFalse($diagnostics->markQueueRestartRequired('mail-settings-updated'));
        $this->assertFalse($diagnostics->overview()['queue']['restart_required']);
    }

    public function test_queue_lifecycle_records_start_success_and_final_failure_without_payloads(): void
    {
        $diagnostics = app(RuntimeDiagnostics::class);
        $diagnostics->recordQueueStarted('database', 'module-events', 15, 'App\\Jobs\\ModuleEvent');
        $diagnostics->recordQueueSucceeded('database', 'module-events', 15, 'App\\Jobs\\ModuleEvent');

        $queueHeartbeat = SystemHeartbeat::query()
            ->where('key', 'like', RuntimeDiagnostics::QUEUE_HEARTBEAT_PREFIX.'%')
            ->firstOrFail();

        $this->assertSame('module-events', $queueHeartbeat->metadata['queue'] ?? null);
        $this->assertSame('succeeded', $queueHeartbeat->metadata['state'] ?? null);
        $this->assertNotEmpty($queueHeartbeat->metadata['last_started_at'] ?? null);
        $this->assertNotEmpty($queueHeartbeat->metadata['last_succeeded_at'] ?? null);
        $this->assertArrayNotHasKey('payload', $queueHeartbeat->metadata ?? []);

        $diagnostics->recordQueueFailed(
            'database',
            'module-events',
            new RuntimeException('Sensitive failure text'),
            16,
            'App\\Jobs\\ModuleEvent',
        );

        $failedHeartbeat = SystemHeartbeat::query()->findOrFail(RuntimeDiagnostics::QUEUE_WORKER_FAILED);
        $this->assertSame(RuntimeException::class, $failedHeartbeat->metadata['exception_class'] ?? null);
        $this->assertStringNotContainsString('Sensitive failure text', json_encode($failedHeartbeat->metadata));
    }

    public function test_runtime_diagnostics_groups_pending_and_failed_jobs_by_queue(): void
    {
        $this->insertPendingJob('mail', now()->subMinute()->getTimestamp());
        $this->insertPendingJob('module-events', now()->subMinutes(3)->getTimestamp());
        $this->insertFailedJob('failed-module-job', 'module-events', now()->subMinute());

        app(RuntimeDiagnostics::class)->recordSchedulerHeartbeat();
        $overview = app(RuntimeDiagnostics::class)->overview();

        $this->assertSame(2, $overview['jobs']['pending']);
        $this->assertSame(1, $overview['jobs']['failed']);
        $this->assertTrue($overview['queue']['requires_worker']);
        $this->assertSame(['mail', 'module-events'], array_column($overview['jobs']['queues'], 'name'));
        $moduleQueue = collect($overview['jobs']['queues'])->firstWhere('name', 'module-events');
        $this->assertSame(1, $moduleQueue['pending']);
        $this->assertSame(1, $moduleQueue['failed']);
    }

    public function test_cleanup_keeps_pending_mail_and_deletes_only_expired_terminal_records(): void
    {
        DB::table('mail_deliveries')->insert([
            [
                'type' => 'password_reset',
                'recipient' => 'pending@example.test',
                'mode' => 'database',
                'status' => MailDelivery::STATUS_PENDING,
                'queued_at' => now()->subDays(120),
                'sent_at' => null,
                'failed_at' => null,
                'created_at' => now()->subDays(120),
                'updated_at' => now()->subDays(120),
            ],
            [
                'type' => 'password_changed',
                'recipient' => 'expired@example.test',
                'mode' => 'database',
                'status' => MailDelivery::STATUS_SENT,
                'queued_at' => now()->subDays(40),
                'sent_at' => now()->subDays(40),
                'failed_at' => null,
                'created_at' => now()->subDays(40),
                'updated_at' => now()->subDays(40),
            ],
        ]);
        $this->insertFailedJob('expired-failed-job', 'default', now()->subDays(100));
        $this->insertFailedJob('current-failed-job', 'default', now()->subDays(10));
        SystemHeartbeat::query()->create([
            'key' => RuntimeDiagnostics::QUEUE_HEARTBEAT_PREFIX.'expired',
            'last_seen_at' => now()->subDays(40),
            'metadata' => ['queue' => 'old-module'],
        ]);

        $result = app(QueueMaintenance::class)->cleanup();

        $this->assertSame(1, $result['mail_deliveries_deleted']);
        $this->assertSame(1, $result['failed_jobs_deleted']);
        $this->assertSame(1, $result['heartbeats_deleted']);
        $this->assertDatabaseHas('mail_deliveries', ['recipient' => 'pending@example.test']);
        $this->assertDatabaseMissing('mail_deliveries', ['recipient' => 'expired@example.test']);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'current-failed-job']);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'expired-failed-job']);
        $this->assertDatabaseHas('system_heartbeats', ['key' => RuntimeDiagnostics::QUEUE_MAINTENANCE]);
    }

    public function test_administrator_can_manage_failed_jobs_without_seeing_payload_or_exception_message(): void
    {
        $admin = Admin::factory()->administrator()->create();
        $this->insertFailedJob(
            'visible-failed-job',
            'mail',
            now(),
            displayName: 'App\\Jobs\\Mail\\SendUserMailNotification',
            exception: 'RuntimeException: Secret SMTP message',
        );

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/system/queue')
            ->assertOk()
            ->assertSee('Управление очередями')
            ->assertSee('SendUserMailNotification')
            ->assertSee('RuntimeException')
            ->assertDontSee('Secret SMTP message')
            ->assertDontSee('secret-payload-value');

        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:retry', ['id' => ['visible-failed-job']])
            ->andReturn(0);

        $this->actingAs($admin, 'admin')
            ->post('/admin/settings/system/queue/visible-failed-job/retry')
            ->assertRedirect(route('admin.settings.system.queue'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'queue.failed_job_retried']);

        $this->actingAs($admin, 'admin')
            ->delete('/admin/settings/system/queue/visible-failed-job')
            ->assertRedirect(route('admin.settings.system.queue'));

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'visible-failed-job']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'queue.failed_job_deleted']);
    }

    public function test_editor_cannot_open_or_change_queue_management(): void
    {
        $editor = Admin::factory()->editor()->create();

        $this->actingAs($editor, 'admin')
            ->get('/admin/settings/system/queue')
            ->assertForbidden();
        $this->actingAs($editor, 'admin')
            ->post('/admin/settings/system/queue/cleanup')
            ->assertForbidden();
    }

    public function test_mail_configuration_change_marks_an_existing_worker_for_restart(): void
    {
        $diagnostics = app(RuntimeDiagnostics::class);
        $this->assertFalse($diagnostics->markQueueRestartRequired('mail-settings-updated'));

        $diagnostics->recordQueueSucceeded('database', 'mail', 1, 'MailJob');
        $this->assertTrue($diagnostics->markQueueRestartRequired('mail-settings-updated'));

        $overview = $diagnostics->overview();
        $this->assertTrue($overview['queue']['restart_required']);
        $this->assertContains(
            'Для загрузки актуальных настроек приложения требуется перезапуск обработчика очереди.',
            $overview['warnings'],
        );

        $diagnostics->clearQueueRestartRequired();
        $this->assertFalse($diagnostics->overview()['queue']['restart_required']);
    }

    private function insertPendingJob(string $queue, int $createdAt): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $createdAt,
            'created_at' => $createdAt,
        ]);
    }

    private function insertFailedJob(
        string $uuid,
        string $queue,
        mixed $failedAt,
        string $displayName = 'App\\Jobs\\ExampleJob',
        string $exception = 'RuntimeException: Failure',
    ): void {
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => $queue,
            'payload' => json_encode([
                'displayName' => $displayName,
                'data' => ['command' => 'secret-payload-value'],
            ], JSON_THROW_ON_ERROR),
            'exception' => $exception,
            'failed_at' => $failedAt,
        ]);
    }
}
