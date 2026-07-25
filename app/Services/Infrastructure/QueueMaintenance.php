<?php

namespace App\Services\Infrastructure;

use App\Models\MailDelivery;
use App\Models\SystemHeartbeat;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class QueueMaintenance
{
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly RuntimeDiagnostics $runtimeDiagnostics,
    ) {}

    /**
     * @return array{
     *     mail_delivery_retention_days: int,
     *     failed_job_retention_days: int,
     *     heartbeat_retention_days: int,
     *     mail_deliveries_total: int,
     *     mail_deliveries_expired: int,
     *     failed_jobs_total: int,
     *     failed_jobs_expired: int,
     *     queue_heartbeats_total: int,
     *     queue_heartbeats_expired: int,
     *     last_cleaned_at: Carbon|null
     * }
     */
    public function statistics(): array
    {
        $mailDays = $this->retentionDays('mail_delivery_retention_days', 30);
        $failedDays = $this->retentionDays('failed_job_retention_days', 90);
        $heartbeatDays = $this->retentionDays('heartbeat_retention_days', 30);
        $mailThreshold = now()->subDays($mailDays);
        $failedThreshold = now()->subDays($failedDays);
        $heartbeatThreshold = now()->subDays($heartbeatDays);

        $statistics = [
            'mail_delivery_retention_days' => $mailDays,
            'failed_job_retention_days' => $failedDays,
            'heartbeat_retention_days' => $heartbeatDays,
            'mail_deliveries_total' => 0,
            'mail_deliveries_expired' => 0,
            'failed_jobs_total' => 0,
            'failed_jobs_expired' => 0,
            'queue_heartbeats_total' => 0,
            'queue_heartbeats_expired' => 0,
            'last_cleaned_at' => null,
        ];

        try {
            if (Schema::hasTable('mail_deliveries')) {
                $statistics['mail_deliveries_total'] = MailDelivery::query()->count();
                $statistics['mail_deliveries_expired'] = $this->expiredMailDeliveries($mailThreshold)->count();
            }
        } catch (Throwable) {
            // Safe zero values are sufficient while the database is unavailable.
        }

        try {
            [$connection, $table] = $this->failedJobsConnection();
            if ($connection->getSchemaBuilder()->hasTable($table)) {
                $statistics['failed_jobs_total'] = $connection->table($table)->count();
                $statistics['failed_jobs_expired'] = $connection->table($table)
                    ->where('failed_at', '<', $failedThreshold)
                    ->count();
            }
        } catch (Throwable) {
            // Safe zero values are sufficient while the database is unavailable.
        }

        try {
            if (Schema::hasTable('system_heartbeats')) {
                $queueHeartbeats = SystemHeartbeat::query()
                    ->where('key', 'like', RuntimeDiagnostics::QUEUE_HEARTBEAT_PREFIX.'%');
                $statistics['queue_heartbeats_total'] = (clone $queueHeartbeats)->count();
                $statistics['queue_heartbeats_expired'] = (clone $queueHeartbeats)
                    ->where('last_seen_at', '<', $heartbeatThreshold)
                    ->count();
                $statistics['last_cleaned_at'] = SystemHeartbeat::query()
                    ->find(RuntimeDiagnostics::QUEUE_MAINTENANCE)?->last_seen_at;
            }
        } catch (Throwable) {
            // Safe zero values are sufficient while the database is unavailable.
        }

        return $statistics;
    }

    /** @return array{mail_deliveries_deleted: int, failed_jobs_deleted: int, heartbeats_deleted: int} */
    public function cleanup(): array
    {
        $statistics = $this->statistics();
        $result = [
            'mail_deliveries_deleted' => $this->deleteMailDeliveries(
                now()->subDays($statistics['mail_delivery_retention_days']),
            ),
            'failed_jobs_deleted' => $this->deleteFailedJobs(
                now()->subDays($statistics['failed_job_retention_days']),
            ),
            'heartbeats_deleted' => $this->deleteQueueHeartbeats(
                now()->subDays($statistics['heartbeat_retention_days']),
            ),
        ];

        $this->runtimeDiagnostics->recordQueueMaintenance($result);

        return $result;
    }

    private function deleteMailDeliveries(Carbon $threshold): int
    {
        try {
            if (Schema::hasTable('mail_deliveries') === false) {
                return 0;
            }

            $deleted = 0;
            do {
                $ids = $this->expiredMailDeliveries($threshold)
                    ->orderBy('id')
                    ->limit(self::BATCH_SIZE)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    break;
                }

                $deleted += MailDelivery::query()->whereIn('id', $ids)->delete();
            } while (count($ids) === self::BATCH_SIZE);

            return $deleted;
        } catch (Throwable) {
            return 0;
        }
    }

    private function deleteFailedJobs(Carbon $threshold): int
    {
        try {
            [$connection, $table] = $this->failedJobsConnection();
            if ($connection->getSchemaBuilder()->hasTable($table) === false) {
                return 0;
            }

            $deleted = 0;
            do {
                $ids = $connection->table($table)
                    ->where('failed_at', '<', $threshold)
                    ->orderBy('id')
                    ->limit(self::BATCH_SIZE)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    break;
                }

                $deleted += $connection->table($table)->whereIn('id', $ids)->delete();
            } while (count($ids) === self::BATCH_SIZE);

            return $deleted;
        } catch (Throwable) {
            return 0;
        }
    }

    private function deleteQueueHeartbeats(Carbon $threshold): int
    {
        try {
            if (Schema::hasTable('system_heartbeats') === false) {
                return 0;
            }

            return SystemHeartbeat::query()
                ->where('key', 'like', RuntimeDiagnostics::QUEUE_HEARTBEAT_PREFIX.'%')
                ->where('last_seen_at', '<', $threshold)
                ->delete();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return Builder<MailDelivery> */
    private function expiredMailDeliveries(Carbon $threshold): Builder
    {
        return MailDelivery::query()
            ->whereIn('status', [
                MailDelivery::STATUS_SENT,
                MailDelivery::STATUS_FAILED,
                MailDelivery::STATUS_SKIPPED,
            ])
            ->where('updated_at', '<', $threshold);
    }

    /** @return array{0: Connection, 1: string} */
    private function failedJobsConnection(): array
    {
        $defaultConnection = (string) config('database.default');
        $connectionName = (string) config('queue.failed.database', '');

        return [
            $this->database->connection($connectionName !== '' ? $connectionName : $defaultConnection),
            (string) config('queue.failed.table', 'failed_jobs'),
        ];
    }

    private function retentionDays(string $key, int $default): int
    {
        return max(1, min(3650, (int) config("cms.queue.{$key}", $default)));
    }
}
