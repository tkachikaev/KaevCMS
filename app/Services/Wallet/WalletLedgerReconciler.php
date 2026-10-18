<?php

namespace App\Services\Wallet;

use Illuminate\Support\Facades\DB;

/** Full, sequential ledger check. Never repairs balances or ledger rows. */
final class WalletLedgerReconciler
{
    public function check(int $walletId): WalletReconciliationResult
    {
        if ($walletId <= 0) {
            return new WalletReconciliationResult($walletId, false, 'wallet_missing', 0);
        }

        // The transaction and wallet row lock provide a consistent snapshot
        // relative to the official wallet engine, which takes the same lock.
        return DB::transaction(function () use ($walletId): WalletReconciliationResult {
            $wallet = DB::table('wallets')->where('id', $walletId)->lockForUpdate()->first();
            if ($wallet === null) {
                return new WalletReconciliationResult($walletId, false, 'wallet_missing', 0);
            }

            $expected = 0;
            $count = 0;
            $failure = null;
            $maximum = (string) PHP_INT_MAX;
            $isValidAmount = static function (mixed $number) use ($maximum): bool {
                $raw = (string) $number;

                return preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $raw) === 1
                    && (strlen($raw) < strlen($maximum)
                        || (strlen($raw) === strlen($maximum) && strcmp($raw, $maximum) <= 0));
            };

            // chunkById avoids loading huge ledgers into PHP memory.
            DB::table('wallet_entries')->where('wallet_id', $walletId)
                ->chunkById(500, function ($entries) use (&$expected, &$count, &$failure, $isValidAmount): bool {
                    foreach ($entries as $entry) {
                        $count++;
                        if (! $isValidAmount($entry->amount) || (int) $entry->amount === 0
                            || ! $isValidAmount($entry->balance_before) || ! $isValidAmount($entry->balance_after)) {
                            $failure = 'invalid_amount';

                            return false;
                        }
                        if ((int) $entry->balance_before !== $expected) {
                            $failure = 'ledger_gap';

                            return false;
                        }
                        $amount = (int) $entry->amount;
                        if ($entry->direction === 'credit') {
                            if ($amount > PHP_INT_MAX - $expected) {
                                $failure = 'overflow';

                                return false;
                            }
                            $expected += $amount;
                        } elseif ($entry->direction === 'debit') {
                            if ($amount > $expected) {
                                $failure = 'negative_balance';

                                return false;
                            }
                            $expected -= $amount;
                        } else {
                            $failure = 'invalid_direction';

                            return false;
                        }
                        if ((int) $entry->balance_after !== $expected) {
                            $failure = 'incorrect_entry_balance';

                            return false;
                        }
                    }

                    return true;
                }, 'id');

            if ($failure !== null) {
                return new WalletReconciliationResult($walletId, false, $failure, $count);
            }
            if (! $isValidAmount($wallet->balance) || (int) $wallet->balance !== $expected) {
                return new WalletReconciliationResult($walletId, false, 'wallet_balance_mismatch', $count);
            }

            return new WalletReconciliationResult($walletId, true, 'ok', $count);
        }, 3);
    }
}
