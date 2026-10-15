<?php

namespace App\Services\Wallet;

/** Read-only result; never use this as authorization to modify a balance. */
final readonly class WalletReconciliationResult
{
    public function __construct(
        public int $walletId,
        public bool $valid,
        public string $code,
        public int $checkedEntries,
    ) {}
}
