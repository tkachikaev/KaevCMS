<?php

namespace App\Services\Infrastructure;

use App\Models\SystemHeartbeat;
use App\Services\MailSettings;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class RuntimeDiagnostics
{
    public const SCHEDULER_HEARTBEAT = 'scheduler';

    public const QUEUE_WORKER_HEARTBEAT = 'queue-worker';

    public const QUEUE_WORKER_SUCCEEDED = 'queue-worker-succeeded';

    public const QUEUE_WORKER_FAILED = 'queue-worker-failed';

    public const QUEUE_RESTART_REQUIRED = 'queue-restart-required';

    public const QUEUE_MAINTENANCE = 'queue-maintenance';

    public const QUEUE_HEARTBEAT_PREFIX = 'queue-worker-queue:';

    private const HEARTBEAT_FRESH_MINUTES = 3;

    private const STALE_JOB_MINUTES = 2;

    private readonly Carbon $processStartedAt;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly MailSettings $mailSettings,
    ) {
        $this->processStartedAt = now()->copy();
    }

    public function recordSchedulerHeartbeat(): void
    {
        $this->touch(self::SCHEDULER_HEARTBEAT, [
            'command' => 'schedule:run',
        ]);
    }

    /**
     * Compatibility wrapper retained for integrations added in 0.23.7.
     */
    public function recordQueueWorker(string $connection, ?string $queue): void
    {
        $this->recordQueueStarted($connection, $queue);
    }

    public function recordQueueStarted(
        string $connection,
        ?string $queue,
        string|int|null $jobId = null,
        ?string $jobName = null,
    ): void {
        $this->recordQueueEvent('started', $connection, $queue, $jobId, $jobName);
    }

    public function recordQueueSucceeded(
        string $connection,
        ?string $queue,
        string|int|null $jobId = null,
        ?string $jobName = null,
    ): void {
        if (! $this->isDatabaseQueueConnection($connection)) {
            return;
        }

        $this->recordQueueEvent('succeeded', $connection, $queue, $jobId, $jobName);
        $this->touch(self::QUEUE_WORKER_SUCCEEDED, $this->eventMetadata(
            state: 'succeeded',
            connection: $connection,
            queue: $queue,
            jobId: $jobId,
            jobName: $jobName,
        ));
        $this->clearSatisfiedRestartRequest();
    }

    public function recordQueueException(
        string $connection,
        ?string $queue,
        Throwable $exception,
        string|int|null $jobId = null,
        ?string $jobName = null,
    ): void {
        if (! $this->isDatabaseQueueConnection($connection)) {
            return;
        }

        $this->recordQueueEvent(
            state: 'exception',
            connection: $connection,
            queue: $queue,
            jobId: $jobId,
            jobName: $jobName,
            exceptionClass: $exception::class,
        );
    }

    public function recordQueueFailed(
        string $connection,
        ?string $queue,
        Throwable $exception,
        string|int|null $jobId = null,
        ?string $jobName = null,
    ): void {
        if (! $this->isDatabaseQueueConnection($connection)) {
            return;
        }

        $this->recordQueueEvent(
            state: 'failed',
            connection: $connection,
            queue: $queue,
            jobId: $jobId,
            jobName: $jobName,
            exceptionClass: $exception::class,
        );
        $this->touch(self::QUEUE_WORKER_FAILED, $this->eventMetadata(
            state: 'failed',
            connection: $connection,
            queue: $queue,
            jobId: $jobId,
            jobName: $jobName,
            exceptionClass: $exception::class,
        ));
    }

    public function markQueueRestartRequired(string $reason): bool
    {
        $heartbeat = $this->heartbeatRecord(self::QUEUE_WORKER_HEARTBEAT);
        $metadata = is_array($heartbeat?->metadata) ? $heartbeat->metadata : [];

        if ($heartbeat === null || ($metadata['source'] ?? null) !== 'worker') {
            return false;
        }

        $this->touch(self::QUEUE_RESTART_REQUIRED, [
            'reason' => Str::limit($reason, 100, ''),
            'requested_at' => now()->toIso8601String(),
        ]);

        return true;
    }

    public function clearQueueRestartRequired(): void
    {
        try {
            if (Schema::hasTable('system_heartbeats')) {
                SystemHeartbeat::query()->whereKey(self::QUEUE_RESTART_REQUIRED)->delete();
            }
        } catch (Throwable) {
            // The restart command itself must remain usable when diagnostics fail.
        }
    }

    public function recordQueueMaintenance(array $statistics): void
    {
        $this->touch(self::QUEUE_MAINTENANCE, [
            'mail_deliveries_deleted' => (int) ($statistics['mail_deliveries_deleted'] ?? 0),
            'failed_jobs_deleted' => (int) ($statistics['failed_jobs_deleted'] ?? 0),
            'heartbeats_deleted' => (int) ($statistics['heartbeats_deleted'] ?? 0),
        ]);
    }

    /**
     * @return array{
     *     overall_state: string,
     *     scheduler: array{state: string, status: string, details: string, last_seen_at: Carbon|null, fresh: bool},
     *     queue: array{state: string, status: string, details: string, last_seen_at: Carbon|null, last_succeeded_at: Carbon|null, last_failed_at: Carbon|null, fresh: bool, requires_worker: bool, mode: string, restart_required: bool},
     *     jobs: array{available: bool, pending: int, failed: int, oldest_pending_at: Carbon|null, last_failed_at: Carbon|null, queues: list<array{name: string, pending: int, failed: int, oldest_pending_at: Carbon|null, last_activity_at: Carbon|null, last_succeeded_at: Carbon|null, last_failed_at: Carbon|null, state: string}>},
     *     warnings: list<string>
     * }
     */
    public function overview(): array
    {
        $schedulerAt = $this->heartbeat(self::SCHEDULER_HEARTBEAT);
        $queueAt = $this->heartbeat(self::QUEUE_WORKER_HEARTBEAT);
        $queueSucceededAt = $this->heartbeat(self::QUEUE_WORKER_SUCCEEDED);
        $queueFailedAt = $this->heartbeat(self::QUEUE_WORKER_FAILED);
        $restartRequiredAt = $this->heartbeat(self::QUEUE_RESTART_REQUIRED);
        $schedulerFresh = $this->isFresh($schedulerAt);
        $queueFresh = $this->isFresh($queueAt);
        $jobs = $this->jobStatistics();
        $mailMode = $this->mailSettings->deliveryMode();
        $requiresWorker = $mailMode === MailSettings::MODE_DATABASE || $jobs['pending'] > 0;
        $restartRequired = $restartRequiredAt !== null;
        $warnings = [];

        $scheduler = $this->schedulerStatus($schedulerAt, $schedulerFresh);
        $queue = $this->queueStatus(
            mode: $mailMode,
            requiresWorker: $requiresWorker,
            schedulerFresh: $schedulerFresh,
            queueAt: $queueAt,
            queueSucceededAt: $queueSucceededAt,
            queueFailedAt: $queueFailedAt,
            queueFresh: $queueFresh,
            restartRequired: $restartRequired,
            jobs: $jobs,
        );

        if (! $schedulerFresh) {
            $warnings[] = $schedulerAt === null
                ? __('Laravel Scheduler has not recorded a successful run yet.')
                : __('Laravel Scheduler has not run for more than three minutes.');
        }

        if ($jobs['pending'] > 0 && $this->isStale($jobs['oldest_pending_at']) && ! $queueFresh) {
            $warnings[] = __('Database queue jobs have been waiting for more than two minutes without recent processing activity.');
        }

        if ($jobs['failed'] > 0) {
            $warnings[] = __('Failed queue jobs: :count.', [
                'count' => $jobs['failed'],
            ]);
        }

        if ($restartRequired) {
            $warnings[] = __('A queue worker restart is required to load the latest application settings.');
        }

        return [
            'overall_state' => $this->worstState([
                $scheduler['state'],
                $queue['state'],
                $jobs['failed'] > 0 ? 'warning' : 'success',
            ]),
            'scheduler' => $scheduler,
            'queue' => $queue,
            'jobs' => $jobs,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{state: string, status: string, details: string, last_seen_at: Carbon|null, fresh: bool}
     */
    private function schedulerStatus(?Carbon $lastSeenAt, bool $fresh): array
    {
        if ($fresh && $lastSeenAt !== null) {
            return [
                'state' => 'success',
                'status' => __('Working'),
                'details' => __('Last successful run: :time', ['time' => $lastSeenAt->diffForHumans()]),
                'last_seen_at' => $lastSeenAt,
                'fresh' => true,
            ];
        }

        return [
            'state' => $lastSeenAt === null ? 'warning' : 'danger',
            'status' => $lastSeenAt === null ? __('Not recorded') : __('Overdue'),
            'details' => $lastSeenAt === null
                ? __('Run php artisan schedule:run every minute on the server.')
                : __('Last successful run: :time', ['time' => $lastSeenAt->diffForHumans()]),
            'last_seen_at' => $lastSeenAt,
            'fresh' => false,
        ];
    }

    /**
     * @param  array{available: bool, pending: int, failed: int, oldest_pending_at: Carbon|null, last_failed_at: Carbon|null, queues: list<array{name: string, pending: int, failed: int, oldest_pending_at: Carbon|null, last_activity_at: Carbon|null, last_succeeded_at: Carbon|null, last_failed_at: Carbon|null, state: string}>}  $jobs
     * @return array{state: string, status: string, details: string, last_seen_at: Carbon|null, last_succeeded_at: Carbon|null, last_failed_at: Carbon|null, fresh: bool, requires_worker: bool, mode: string, restart_required: bool}
     */
    private function queueStatus(
        string $mode,
        bool $requiresWorker,
        bool $schedulerFresh,
        ?Carbon $queueAt,
        ?Carbon $queueSucceededAt,
        ?Carbon $queueFailedAt,
        bool $queueFresh,
        bool $restartRequired,
        array $jobs,
    ): array {
        $base = [
            'last_seen_at' => $queueAt,
            'last_succeeded_at' => $queueSucceededAt,
            'last_failed_at' => $queueFailedAt,
            'fresh' => $queueFresh,
            'requires_worker' => $requiresWorker,
            'mode' => $mode,
            'restart_required' => $restartRequired,
        ];

        if (! $jobs['available']) {
            return [
                ...$base,
                'state' => 'danger',
                'status' => __('Unavailable'),
                'details' => __('Queue tables are missing or cannot be read.'),
            ];
        }

        if ($restartRequired) {
            return [
                ...$base,
                'state' => 'warning',
                'status' => __('Restart required'),
                'details' => __('Restart the queue worker so it loads the latest application settings.'),
            ];
        }

        if (! $requiresWorker) {
            return [
                ...$base,
                'state' => 'success',
                'status' => $mode === MailSettings::MODE_SYNC ? __('Synchronous mode') : __('Background mode'),
                'details' => __('No database queue jobs are waiting.'),
            ];
        }

        $stalePendingJob = $jobs['pending'] > 0 && $this->isStale($jobs['oldest_pending_at']);

        if ($stalePendingJob && ! $queueFresh) {
            return [
                ...$base,
                'state' => 'danger',
                'status' => __('Jobs are not being processed'),
                'details' => $schedulerFresh
                    ? __('Scheduler is running, but the database queue has no recent processing activity.')
                    : __('Scheduler and the database queue require attention.'),
            ];
        }

        if (! $schedulerFresh && ! $queueFresh) {
            return [
                ...$base,
                'state' => 'warning',
                'status' => __('Waiting for Scheduler'),
                'details' => __('Database queue processing depends on Scheduler or a permanent queue worker.'),
            ];
        }

        if ($jobs['pending'] > 0) {
            return [
                ...$base,
                'state' => 'success',
                'status' => __('Processing'),
                'details' => $queueSucceededAt !== null
                    ? __('Last successful job: :time', ['time' => $queueSucceededAt->diffForHumans()])
                    : __('Queue processing is available. The first successful result has not been recorded yet.'),
            ];
        }

        return [
            ...$base,
            'state' => 'success',
            'status' => __('Ready'),
            'details' => $queueSucceededAt !== null
                ? __('Last successful job: :time', ['time' => $queueSucceededAt->diffForHumans()])
                : __('Scheduler is running. Queue activity will appear after the first database job.'),
        ];
    }

    /**
     * @return array{available: bool, pending: int, failed: int, oldest_pending_at: Carbon|null, last_failed_at: Carbon|null, queues: list<array{name: string, pending: int, failed: int, oldest_pending_at: Carbon|null, last_activity_at: Carbon|null, last_succeeded_at: Carbon|null, last_failed_at: Carbon|null, state: string}>}
     */
    private function jobStatistics(): array
    {
        $empty = [
            'available' => false,
            'pending' => 0,
            'failed' => 0,
            'oldest_pending_at' => null,
            'last_failed_at' => null,
            'queues' => [],
        ];

        try {
            [$queueConnection, $failedConnection, $jobsTable, $failedJobsTable] = $this->queueConnections();

            if (! $queueConnection->getSchemaBuilder()->hasTable($jobsTable)
                || ! $failedConnection->getSchemaBuilder()->hasTable($failedJobsTable)) {
                return $empty;
            }

            $pendingRows = $queueConnection->table($jobsTable)
                ->select('queue')
                ->selectRaw('COUNT(*) AS pending_count')
                ->selectRaw('MIN(created_at) AS oldest_created_at')
                ->groupBy('queue')
                ->get();

            $failedRows = $failedConnection->table($failedJobsTable)
                ->select('queue')
                ->selectRaw('COUNT(*) AS failed_count')
                ->selectRaw('MAX(failed_at) AS latest_failed_at')
                ->groupBy('queue')
                ->get();

            /** @var array<string, array{name: string, pending: int, failed: int, oldest_pending_at: Carbon|null, last_activity_at: Carbon|null, last_succeeded_at: Carbon|null, last_failed_at: Carbon|null}> $queues */
            $queues = [];

            foreach ($pendingRows as $row) {
                $name = $this->normalizeQueueName($row->queue ?? null);
                $queues[$name] = $this->emptyQueueStatistics($name);
                $queues[$name]['pending'] = (int) ($row->pending_count ?? 0);
                $queues[$name]['oldest_pending_at'] = is_numeric($row->oldest_created_at ?? null)
                    ? Carbon::createFromTimestamp((int) $row->oldest_created_at)
                    : null;
            }

            foreach ($failedRows as $row) {
                $name = $this->normalizeQueueName($row->queue ?? null);
                $queues[$name] ??= $this->emptyQueueStatistics($name);
                $queues[$name]['failed'] = (int) ($row->failed_count ?? 0);
                $queues[$name]['last_failed_at'] = $this->parseTimestamp($row->latest_failed_at ?? null);
            }

            foreach ($this->queueHeartbeatRecords() as $heartbeat) {
                $metadata = is_array($heartbeat->metadata) ? $heartbeat->metadata : [];
                $name = $this->normalizeQueueName($metadata['queue'] ?? null);
                $queues[$name] ??= $this->emptyQueueStatistics($name);
                $queues[$name]['last_activity_at'] = $heartbeat->last_seen_at;
                $queues[$name]['last_succeeded_at'] = $this->parseTimestamp($metadata['last_succeeded_at'] ?? null);
                $queues[$name]['last_failed_at'] = $this->parseTimestamp($metadata['last_failed_at'] ?? null)
                    ?? $queues[$name]['last_failed_at'];
            }

            ksort($queues, SORT_NATURAL | SORT_FLAG_CASE);

            $queueRows = array_values(array_map(function (array $queue): array {
                $queue['state'] = match (true) {
                    $queue['pending'] > 0
                        && $this->isStale($queue['oldest_pending_at'])
                        && ! $this->isFresh($queue['last_activity_at']) => 'danger',
                    $queue['failed'] > 0 => 'warning',
                    $queue['pending'] > 0 => 'neutral',
                    default => 'success',
                };

                return $queue;
            }, $queues));

            $oldestPendingAt = null;
            $lastFailedAt = null;
            foreach ($queueRows as $queue) {
                if ($queue['oldest_pending_at'] !== null
                    && ($oldestPendingAt === null || $queue['oldest_pending_at']->lt($oldestPendingAt))) {
                    $oldestPendingAt = $queue['oldest_pending_at'];
                }
                if ($queue['last_failed_at'] !== null
                    && ($lastFailedAt === null || $queue['last_failed_at']->gt($lastFailedAt))) {
                    $lastFailedAt = $queue['last_failed_at'];
                }
            }

            return [
                'available' => true,
                'pending' => array_sum(array_column($queueRows, 'pending')),
                'failed' => array_sum(array_column($queueRows, 'failed')),
                'oldest_pending_at' => $oldestPendingAt,
                'last_failed_at' => $lastFailedAt,
                'queues' => $queueRows,
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /** @return array{0: Connection, 1: Connection, 2: string, 3: string} */
    private function queueConnections(): array
    {
        $defaultConnection = (string) config('database.default');
        $queueConnectionName = (string) config('queue.connections.database.connection', '');
        $failedConnectionName = (string) config('queue.failed.database', '');
        $jobsTable = (string) config('queue.connections.database.table', 'jobs');
        $failedJobsTable = (string) config('queue.failed.table', 'failed_jobs');

        return [
            $this->database->connection($queueConnectionName !== '' ? $queueConnectionName : $defaultConnection),
            $this->database->connection($failedConnectionName !== '' ? $failedConnectionName : $defaultConnection),
            $jobsTable,
            $failedJobsTable,
        ];
    }

    /**
     * @return array{name: string, pending: int, failed: int, oldest_pending_at: Carbon|null, last_activity_at: Carbon|null, last_succeeded_at: Carbon|null, last_failed_at: Carbon|null}
     */
    private function emptyQueueStatistics(string $name): array
    {
        return [
            'name' => $name,
            'pending' => 0,
            'failed' => 0,
            'oldest_pending_at' => null,
            'last_activity_at' => null,
            'last_succeeded_at' => null,
            'last_failed_at' => null,
        ];
    }

    private function recordQueueEvent(
        string $state,
        string $connection,
        ?string $queue,
        string|int|null $jobId = null,
        ?string $jobName = null,
        ?string $exceptionClass = null,
    ): void {
        if (! $this->isDatabaseQueueConnection($connection)) {
            return;
        }

        $queue = $this->normalizeQueueName($queue);
        $metadata = $this->eventMetadata(
            state: $state,
            connection: $connection,
            queue: $queue,
            jobId: $jobId,
            jobName: $jobName,
            exceptionClass: $exceptionClass,
        );

        $this->touch(self::QUEUE_WORKER_HEARTBEAT, $metadata);
        $this->touchQueue($queue, $state, $metadata);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventMetadata(
        string $state,
        string $connection,
        ?string $queue,
        string|int|null $jobId = null,
        ?string $jobName = null,
        ?string $exceptionClass = null,
    ): array {
        return array_filter([
            'state' => $state,
            'connection' => $connection,
            'queue' => $this->normalizeQueueName($queue),
            'job_id' => $jobId !== null ? Str::limit((string) $jobId, 100, '') : null,
            'job_name' => $jobName !== null ? Str::limit($jobName, 190, '') : null,
            'exception_class' => $exceptionClass !== null ? Str::limit($exceptionClass, 190, '') : null,
            'process_started_at' => $this->processStartedAt->toIso8601String(),
            'source' => config('cms.queue.scheduler_drain_active', false) ? 'scheduler-drain' : 'worker',
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $metadata */
    private function touchQueue(string $queue, string $state, array $metadata): void
    {
        try {
            if (! Schema::hasTable('system_heartbeats')) {
                return;
            }

            $key = self::QUEUE_HEARTBEAT_PREFIX.substr(hash('sha256', $queue), 0, 32);
            $existing = SystemHeartbeat::query()->find($key);
            $values = is_array($existing?->metadata) ? $existing->metadata : [];
            $timestampKey = match ($state) {
                'started' => 'last_started_at',
                'succeeded' => 'last_succeeded_at',
                'failed' => 'last_failed_at',
                default => 'last_exception_at',
            };

            $values = [
                ...$values,
                ...$metadata,
                'queue' => $queue,
                $timestampKey => now()->toIso8601String(),
            ];

            SystemHeartbeat::query()->updateOrCreate(
                ['key' => $key],
                [
                    'last_seen_at' => now(),
                    'metadata' => $values,
                ],
            );
        } catch (Throwable) {
            // Monitoring must never break queue processing.
        }
    }

    /** @param array<string, mixed> $metadata */
    private function touch(string $key, array $metadata): void
    {
        try {
            if (! Schema::hasTable('system_heartbeats')) {
                return;
            }

            SystemHeartbeat::query()->updateOrCreate(
                ['key' => $key],
                [
                    'last_seen_at' => now(),
                    'metadata' => $metadata,
                ],
            );
        } catch (Throwable) {
            // Monitoring must never break Scheduler or queue processing.
        }
    }

    private function clearSatisfiedRestartRequest(): void
    {
        try {
            if (! Schema::hasTable('system_heartbeats')) {
                return;
            }

            $request = SystemHeartbeat::query()->find(self::QUEUE_RESTART_REQUIRED);
            if ($request !== null && $this->processStartedAt->gte($request->last_seen_at)) {
                $request->delete();
            }
        } catch (Throwable) {
            // Restart diagnostics must not affect job execution.
        }
    }

    private function heartbeat(string $key): ?Carbon
    {
        return $this->heartbeatRecord($key)?->last_seen_at;
    }

    private function heartbeatRecord(string $key): ?SystemHeartbeat
    {
        try {
            if (! Schema::hasTable('system_heartbeats')) {
                return null;
            }

            return SystemHeartbeat::query()->find($key);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<SystemHeartbeat> */
    private function queueHeartbeatRecords(): array
    {
        try {
            if (! Schema::hasTable('system_heartbeats')) {
                return [];
            }

            return SystemHeartbeat::query()
                ->where('key', 'like', self::QUEUE_HEARTBEAT_PREFIX.'%')
                ->get()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function isDatabaseQueueConnection(string $connection): bool
    {
        return $connection === 'database';
    }

    private function normalizeQueueName(mixed $queue): string
    {
        $name = is_scalar($queue) ? trim((string) $queue) : '';

        return $name !== '' ? Str::limit($name, 190, '') : 'default';
    }

    private function isFresh(?Carbon $timestamp): bool
    {
        return $timestamp !== null && $timestamp->gte(now()->subMinutes(self::HEARTBEAT_FRESH_MINUTES));
    }

    private function isStale(?Carbon $timestamp): bool
    {
        return $timestamp !== null && $timestamp->lt(now()->subMinutes(self::STALE_JOB_MINUTES));
    }

    /** @param list<string> $states */
    private function worstState(array $states): string
    {
        foreach (['danger', 'warning', 'neutral', 'success'] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return 'neutral';
    }
}
