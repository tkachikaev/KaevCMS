<?php

namespace KaevCMS\Modules\Referrals\Console\Commands;

use Illuminate\Console\Command;
use KaevCMS\Modules\Referrals\Services\ReferralRewardGrantService;

final class GrantReferralRewardsCommand extends Command
{
    protected $signature = 'kaevcms:referrals-grants {--batch=30 : Maximum successful grants per run}';

    protected $description = 'Deliver earned referral milestones to Web Inventory idempotently';

    public function handle(ReferralRewardGrantService $rewards): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if ($batch === false || $batch < 1 || $batch > 200) {
            $this->error('The batch must be between 1 and 200.');

            return self::FAILURE;
        }

        $counts = $rewards->grantBatch($batch);
        $this->components->info(sprintf(
            'Granted: %d; deferred: %d; failed: %d; inspected: %d',
            $counts['granted'], $counts['deferred'], $counts['failed'], $counts['inspected'],
        ));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
