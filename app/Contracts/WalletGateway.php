<?php

namespace App\Contracts;

use App\Models\Admin;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\WalletStatusEvent;
use App\Services\Wallet\WalletSource;

/** Server-side wallet interface. Never expose these methods as unguarded HTTP/Livewire actions. */
interface WalletGateway
{
    public function creditFromModule(WalletSource $source, User $recipient, int $amount, string $key, string $reference): WalletEntry;

    public function debitForOwner(User $owner, int $amount, string $key, string $reference): WalletEntry;

    public function manualCredit(Admin $admin, User $recipient, int $amount, string $key, string $reason): WalletEntry;

    public function changeBlockStatus(Admin $admin, User $recipient, bool $blocked, string $key, string $reason): WalletStatusEvent;

    public function manualDebit(Admin $admin, User $recipient, int $amount, string $key, string $reason): WalletEntry;
}
