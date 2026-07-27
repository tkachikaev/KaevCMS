<?php

namespace KaevCMS\Modules\DailyRewards\Exceptions;

enum DailyRewardClaimFailure: string
{
    case CalendarUnavailable = 'calendar_unavailable';
    case DayUnavailable = 'day_unavailable';
    case AccountUnavailable = 'account_unavailable';
    case AlreadyClaimed = 'already_claimed';
    case NoRewards = 'no_rewards';
    case Invalid = 'invalid';

    public function translationKey(): string
    {
        return 'module-daily-rewards::messages.claim_'.$this->value;
    }
}
