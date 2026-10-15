<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Models\Wallet;

/** Read-only presentation of the current CMS user's virtual wallet. */
final class WalletAccountReadService
{
    public function __construct(private readonly WalletAppearanceSettings $appearance) {}

    /** @return array{balance:int,formatted:string,label:string,icon_url:string|null,blocked:bool} */
    public function forUser(User $user): array
    {
        // Wallet is optional for old/new users until their first transaction.
        // Never insert a wallet row while rendering a page.
        $wallet = Wallet::query()->where('user_id', $user->getKey())->first(['id', 'balance', 'is_blocked']);
        $balance = $wallet?->balance ?? 0;
        $icon = $this->appearance->values();

        return [
            'balance' => $balance,
            'formatted' => number_format($balance, 0, '.', ' '),
            'label' => $this->appearance->label($balance),
            'icon_url' => $icon['icon_url'],
            'blocked' => $wallet?->is_blocked ?? false,
        ];
    }
}
