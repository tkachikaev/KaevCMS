<?php

namespace KaevCMS\Modules\Voting\Models;

use App\Models\GameServer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $game_server_id
 * @property bool $enabled
 * @property mixed $items
 * @property-read GameServer $gameServer
 */
final class VotingServerReward extends Model
{
    protected $table = 'module_voting_server_rewards';

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'items' => 'array'];
    }

    /** @return BelongsTo<GameServer, $this> */
    public function gameServer(): BelongsTo
    {
        return $this->belongsTo(GameServer::class);
    }

    /** @return list<array{item_id:int,amount:int}> Invalid bundles are never eligible. */
    public function configuredItems(): array
    {
        if (! $this->enabled) {
            return [];
        }

        return $this->validatedItems();
    }

    /** @return list<array{item_id:int,amount:int}> */
    public function validatedItems(): array
    {
        $items = $this->items;
        if (! is_array($items) || ! array_is_list($items) || count($items) > 100) {
            return [];
        }

        $validated = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_int($item['item_id'] ?? null) || $item['item_id'] <= 0 || $item['item_id'] > 2147483647
                || ! is_int($item['amount'] ?? null) || $item['amount'] <= 0 || $item['amount'] > 2000000000 || isset($seen[$item['item_id']])) {
                return [];
            }
            $seen[$item['item_id']] = true;
            $validated[] = ['item_id' => $item['item_id'], 'amount' => $item['amount']];
        }

        return $validated;
    }
}
