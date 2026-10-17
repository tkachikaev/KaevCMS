<?php

namespace App\Console\Commands;

use App\Support\Payments\DonationsTrustedAutoload;
use Illuminate\Console\Command;
use KaevCMS\Modules\Donations\Services\DonationPaymentSettlements;
use KaevCMS\Modules\Donations\Services\DonationYooMoneyReconciliation;
use Throwable;

/** Manual recovery/settlement aid. Not scheduled or auto-enabled in C3. */
final class ReconcileDonationPaymentsCommand extends Command
{
    protected $signature = 'kaevcms:donations-reconcile {--scan : Look up missing YooMoney payment IDs via read-only history API} {--order= : Restrict scan to order UUID} {--limit=30 : Max preparations} {--retry-verified : Reverify stored signed callbacks and process pending ones if trusted gates are enabled}';

    protected $description = 'Inspect lost YooMoney notifications without trusting unsigned payment history for wallet credits';

    public function handle(): int
    {
        try {
            DonationsTrustedAutoload::register();
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            if (! is_int($limit) || $limit < 1 || $limit > 100) {
                $this->error('Invalid scan limit.');
                return self::FAILURE;
            }
            if ($this->option('scan')) {
                $result = app(DonationYooMoneyReconciliation::class)->scan($limit, $this->option('order'));
                $this->line(sprintf('Checked %d; new review candidates %d; unavailable scans %d.',
                    $result['scanned'], $result['candidates'], $result['errors']));
            }
            if ($this->option('retry-verified')) {
                $count = app(DonationPaymentSettlements::class)->retryPending($limit);
                $this->line('Reverified and credited: '.$count.'.');
            }
            if (! $this->option('scan') && ! $this->option('retry-verified')) {
                $this->line('Select --scan (read-only API recovery) or --retry-verified (gated settlement).');
            }
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Donation reconciliation unavailable; check provider credentials and test database.');
            return self::FAILURE;
        }
    }
}
