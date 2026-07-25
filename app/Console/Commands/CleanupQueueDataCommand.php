<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\Infrastructure\QueueMaintenance;
use Illuminate\Console\Command;

final class CleanupQueueDataCommand extends Command
{
    protected $signature = 'kaevcms:queue-clean {--dry-run : Show expired record counts without deleting them}';

    protected $description = 'Delete expired mail delivery records, failed jobs and stale per-queue heartbeats';

    public function handle(QueueMaintenance $maintenance, AuditLogger $auditLogger): int
    {
        $statistics = $maintenance->statistics();

        if ($this->option('dry-run')) {
            $this->line(__('Mail delivery records to delete: :count.', [
                'count' => $statistics['mail_deliveries_expired'],
            ]));
            $this->line(__('Failed jobs to delete: :count.', [
                'count' => $statistics['failed_jobs_expired'],
            ]));
            $this->line(__('Queue heartbeat records to delete: :count.', [
                'count' => $statistics['queue_heartbeats_expired'],
            ]));

            return self::SUCCESS;
        }

        $result = $maintenance->cleanup();

        if (array_sum($result) > 0) {
            $auditLogger->system('system', 'queue.retention_cleaned', details: $result);
        }

        $this->info(__('Expired queue service data deleted.'));
        $this->line(__('Mail delivery records: :count.', ['count' => $result['mail_deliveries_deleted']]));
        $this->line(__('Failed jobs: :count.', ['count' => $result['failed_jobs_deleted']]));
        $this->line(__('Queue heartbeat records: :count.', ['count' => $result['heartbeats_deleted']]));

        return self::SUCCESS;
    }
}
