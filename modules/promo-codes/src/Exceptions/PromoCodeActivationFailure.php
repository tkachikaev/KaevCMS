<?php

namespace KaevCMS\Modules\PromoCodes\Exceptions;

enum PromoCodeActivationFailure: string
{
    case Disabled = 'disabled';
    case NotStarted = 'not_started';
    case Expired = 'expired';
    case TotalLimit = 'total_limit';
    case UserLimit = 'user_limit';
    case NoRewards = 'no_rewards';
    case Invalid = 'invalid';

    public function translationKey(): string
    {
        return 'module-promo-codes::messages.activation_'.$this->value;
    }
}
