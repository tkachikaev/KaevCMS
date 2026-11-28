<?php

namespace KaevCMS\Modules\Voting\Console\Commands;

use Illuminate\Console\Command;
use KaevCMS\Modules\Voting\Services\VotingSyncService;
use Throwable;

final class SyncVotingCommand extends Command
{
    protected $signature = 'kaevcms:voting-sync {--pages=4 : Maximum event pages per run (1–4)}';

    protected $description = 'Synchronize KTreon votes and issue eligible rewards to Web Inventory';

    public function handle(VotingSyncService $sync): int
    {
        $pages = filter_var($this->option('pages'), FILTER_VALIDATE_INT);
        if ($pages === false || $pages < 1 || $pages > 4) {
            $this->error('The page count must be between 1 and 4.');

            return self::FAILURE;
        }
        try {
            $result = $sync->sync($pages);
        } catch (Throwable) {
            $this->error('Voting synchronization failed.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Result: %s; pages: %d; events: %d; grants: %d',
            $result['result'], $result['pages'], $result['processed'], $result['granted'],
        ));

        return in_array($result['result'], ['ok', 'idle', 'cooldown', 'changed'], true) ? self::SUCCESS : self::FAILURE;
    }
}
