<?php

namespace App\Contracts;

use App\Models\User;
use App\Models\WalletEntry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/** Optional player-history presentation. Implementations must never write on reads. */
interface WalletTopUpHistory
{
    public function query(User $user, bool $includeInactive): Builder;

    /** @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function rows(User $user, array $ids): array;

    /** @param Collection<int, WalletEntry> $entries
     * @return array<int, array<string, mixed>>
     */
    public function received(User $user, Collection $entries): array;
}
