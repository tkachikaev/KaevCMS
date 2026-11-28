<?php

namespace KaevCMS\Modules\Voting\Models;

use App\Models\GameServer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $project_id
 * @property string $vote_id
 * @property string $player_ref
 * @property int|null $binding_id
 * @property int|null $user_id
 * @property int|null $user_game_account_id
 * @property int|null $game_server_id
 * @property string|null $game_account_login
 * @property string $status
 * @property int $status_version
 * @property string $voted_at
 * @property string $status_changed_at
 * @property mixed $items_snapshot
 * @property string $reward_state
 * @property string|null $reward_reason
 * @property int|null $reward_inventory_grant_id
 * @property Carbon|null $granted_at
 * @property-read GameServer|null $gameServer
 */
final class VotingVote extends Model
{
    protected $table = 'module_voting_votes';

    protected $hidden = ['player_ref'];

    protected function casts(): array
    {
        return [
            'binding_id' => 'integer',
            'user_id' => 'integer',
            'user_game_account_id' => 'integer',
            'game_server_id' => 'integer',
            'status_version' => 'integer',
            'items_snapshot' => 'array',
            'reward_inventory_grant_id' => 'integer',
            'granted_at' => 'datetime',
        ];
    }

    /** @return list<array{item_id:int,amount:int}> */
    public function snapshotItems(): array
    {
        return (new VotingServerReward)->forceFill(['items' => $this->items_snapshot])->validatedItems();
    }

    /** @return BelongsTo<GameServer, $this> */
    public function gameServer(): BelongsTo
    {
        return $this->belongsTo(GameServer::class);
    }
}
