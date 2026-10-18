<?php

namespace App\Console\Commands;

use App\Services\Wallet\WalletLedgerReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Explicit read-only diagnostic. Not scheduled and never repairs financial data. */
final class ReconcileWalletsCommand extends Command
{
    protected $signature = 'kaevcms:wallets-reconcile
        {--user= : Check only this CMS user ID}
        {--limit= : Maximum number of wallets to inspect}';

    protected $description = 'Verify wallet balances against every ledger entry (read-only)';

    public function handle(WalletLedgerReconciler $reconciler): int
    {
        $user = $this->option('user');
        $limit = $this->option('limit');
        foreach ([$user, $limit] as $value) {
            if ($value !== null && (preg_match('/\A[1-9][0-9]{0,17}\z/D', $value) !== 1
                || (float) $value > PHP_INT_MAX)) {
                $this->error('Invalid positive integer option.');

                return self::INVALID;
            }
        }
        $query = DB::table('wallets')->orderBy('id');
        if ($user !== null) {
            $query->where('user_id', (int) $user);
        }
        $checked = 0;
        $failed = 0;
        $limitCount = $limit !== null ? (int) $limit : null;
        $query->chunkById(100, function ($wallets) use ($reconciler, &$checked, &$failed, $limitCount): bool {
            foreach ($wallets as $wallet) {
                if ($limitCount !== null && $checked >= $limitCount) {
                    return false;
                }
                $result = $reconciler->check((int) $wallet->id);
                $checked++;
                if (! $result->valid) {
                    $failed++;
                    $this->error('Wallet #'.$result->walletId.': '.$result->code.' (entries: '.$result->checkedEntries.')');
                }
            }

            return $limitCount === null || $checked < $limitCount;
        }, 'id');
        if ($user !== null && $checked === 0) {
            $this->error('Wallet not found; no data was modified.');

            return self::FAILURE;
        }
        $this->info('Wallets checked: '.$checked.'; inconsistencies: '.$failed.'. No data modified.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
