<?php

namespace KaevCMS\Modules\SupportTickets\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketCleanupService;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings;

final class CleanupSupportTicketsCommand extends Command
{
    protected $signature = 'kaevcms:support-tickets-cleanup
        {--dry-run : Show what would be deleted without changing data}
        {--batch=100 : Number of tickets deleted per transaction}
        {--vacuum : Run SQLite VACUUM after a real cleanup}
        {--scheduled : Respect the automatic-cleanup setting}';

    protected $description = 'Preview or delete expired closed support tickets';

    public function handle(
        SupportTicketSettings $settings,
        SupportTicketCleanupService $cleanup,
    ): int {
        if (! Schema::hasTable('module_support_tickets')) {
            $this->warn('Support ticket tables are not installed.');

            return self::SUCCESS;
        }

        $configuration = $settings->cleanupConfiguration();
        if ($configuration === null) {
            $this->error('Support ticket cleanup was skipped because its settings could not be read.');

            return self::FAILURE;
        }

        if ($this->option('scheduled') && ! $configuration['automatic_cleanup_enabled']) {
            $this->info('Automatic support ticket cleanup is disabled.');

            return self::SUCCESS;
        }

        $retentionMonths = $configuration['retention_months'];
        $dryRun = (bool) $this->option('dry-run');
        $batchSize = max(1, (int) $this->option('batch'));
        $result = $dryRun
            ? $cleanup->preview($retentionMonths)
            : $cleanup->cleanup($retentionMonths, $batchSize);

        $this->table(
            ['Retention', 'Tickets', 'Messages', 'Revisions', 'Oldest', 'Newest'],
            [[
                $retentionMonths === 0 ? 'Forever' : $retentionMonths.' months',
                $result['tickets'],
                $result['messages'],
                $result['revisions'],
                $result['oldest_closed_at'] ?? '-',
                $result['newest_closed_at'] ?? '-',
            ]],
        );

        if ($dryRun) {
            $this->info('Dry run completed. No records were deleted.');

            return self::SUCCESS;
        }

        $this->info('Expired support tickets were deleted in batches.');

        if ((bool) $this->option('vacuum')) {
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $this->warn('VACUUM was skipped because the active database is not SQLite.');
            } else {
                DB::statement('VACUUM');
                $this->info('SQLite VACUUM completed.');
            }
        }

        return self::SUCCESS;
    }
}
