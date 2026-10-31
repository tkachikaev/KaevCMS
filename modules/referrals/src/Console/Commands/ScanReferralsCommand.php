<?php

namespace KaevCMS\Modules\Referrals\Console\Commands;

use Illuminate\Console\Command;
use KaevCMS\Modules\Referrals\Services\ReferralLevelScanner;

final class ScanReferralsCommand extends Command
{
    protected $signature = 'kaevcms:referrals-scan {--batch=30 : Maximum invitation/server pairs per run}';

    protected $description = 'Check referral character milestones using bounded, idempotent GameServer reads';

    public function handle(ReferralLevelScanner $scanner): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if ($batch === false || $batch < 1 || $batch > 200) {
            $this->error('The batch must be between 1 and 200.');

            return self::FAILURE;
        }

        $counts = $scanner->scanBatch($batch);
        $this->components->info(sprintf('Due: %d; discovery: %d', $counts['due'], $counts['discovery']));

        return self::SUCCESS;
    }
}
