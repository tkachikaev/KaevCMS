<?php

namespace App\Exceptions;

use DomainException;

/** Stable, non-sensitive reasons for rejected wallet mutations. */
final class WalletOperationException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Wallet operation rejected: '.$reason.'.');
    }
}
