<?php

namespace App\Support\GameAccounts;

enum ExternalGameAccountWriteResult: string
{
    case Created = 'created';
    case AlreadyExists = 'already_exists';
}
