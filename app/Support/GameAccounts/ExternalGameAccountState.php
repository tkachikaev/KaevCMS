<?php

namespace App\Support\GameAccounts;

enum ExternalGameAccountState: string
{
    case Missing = 'missing';
    case Matching = 'matching';
    case Conflict = 'conflict';
}
