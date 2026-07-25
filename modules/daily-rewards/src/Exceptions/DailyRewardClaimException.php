<?php

namespace KaevCMS\Modules\DailyRewards\Exceptions;

use RuntimeException;

final class DailyRewardClaimException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
