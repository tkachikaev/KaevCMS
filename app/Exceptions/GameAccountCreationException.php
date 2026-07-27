<?php

namespace App\Exceptions;

use App\Models\UserGameAccount;
use App\Support\GameAccounts\GameAccountCreationFailure;
use RuntimeException;

final class GameAccountCreationException extends RuntimeException
{
    public function __construct(
        public readonly GameAccountCreationFailure $failure,
        public readonly ?UserGameAccount $gameAccount = null,
    ) {
        parent::__construct($failure->value);
    }
}
