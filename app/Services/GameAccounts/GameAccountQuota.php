<?php

namespace App\Services\GameAccounts;

use App\Models\User;

final class GameAccountQuota
{
    public function count(User $user): int
    {
        return $user->gameAccountsCountingTowardLimit()->count();
    }

    public function reached(User $user, int $maximum): bool
    {
        return $this->count($user) >= max(1, $maximum);
    }
}
