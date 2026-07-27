<?php

namespace App\Exceptions;

use App\Support\Rewards\RewardTransferFailure;
use RuntimeException;

final class RewardTransferException extends RuntimeException
{
    public function __construct(
        public readonly RewardTransferFailure $failure,
        private readonly ?string $diagnosticCode = null,
    ) {
        parent::__construct($diagnosticCode ?? $failure->value);
    }

    public function translationKey(): string
    {
        return $this->failure->translationKey();
    }

    public function failureCode(): string
    {
        return $this->diagnosticCode ?? $this->failure->value;
    }
}
