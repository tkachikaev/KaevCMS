<?php

namespace App\Services\Wallet;

/** Internal, trusted CMS module identities. Never map a request parameter to this enum. */
enum WalletSource: string
{
    case Donations = 'donations';
    case PromoCodes = 'promo_codes';
    case DailyRewards = 'daily_rewards';
    case Shop = 'shop';
    case ShopRefund = 'shop_refund';
    case Manual = 'manual';

    public function allowsCreditFromModule(): bool
    {
        return in_array($this, [self::Donations, self::PromoCodes, self::DailyRewards, self::ShopRefund], true);
    }
}
