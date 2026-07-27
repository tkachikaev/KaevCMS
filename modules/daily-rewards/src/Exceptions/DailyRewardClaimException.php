<?php

namespace KaevCMS\Modules\DailyRewards\Exceptions;

use RuntimeException;

final class DailyRewardClaimException extends RuntimeException
{
    public function __construct(public readonly DailyRewardClaimFailure $failure)
    {
        parent::__construct($failure->value);
    }

    public function reasonCode(): string
    {
        return $this->failure->value;
    }

    public function translationKey(): string
    {
        return $this->failure->translationKey();
    }
}
