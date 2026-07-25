<?php

namespace App\Exceptions;

use RuntimeException;

final class RewardTransferException extends RuntimeException
{
    public function __construct(
        private readonly string $messageKey,
        private readonly string $failureCode,
    ) {
        parent::__construct($failureCode);
    }

    public function messageKey(): string
    {
        return $this->messageKey;
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
