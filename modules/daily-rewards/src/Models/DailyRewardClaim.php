<?php

namespace KaevCMS\Modules\DailyRewards\Models;

use App\Models\GameServer;
use App\Models\RewardInventoryGrant;
use App\Models\User;
use App\Models\UserGameAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $request_token
 * @property int $calendar_id
 * @property int $day_id
 * @property int|null $user_id
 * @property int|null $user_game_account_id
 * @property int $game_server_id
 * @property int|null $reward_inventory_grant_id
 * @property Carbon $reward_date
 * @property string $user_email
 * @property string $game_account_login
 * @property array<int, mixed> $items_snapshot
 * @property Carbon $claimed_at
 * @property-read DailyRewardCalendar $calendar
 * @property-read DailyRewardDay $day
 * @property-read User|null $user
 * @property-read UserGameAccount|null $gameAccount
 * @property-read GameServer $gameServer
 * @property-read RewardInventoryGrant|null $rewardGrant
 */
final class DailyRewardClaim extends Model
{
    protected $table = 'module_daily_reward_claims';

    protected $fillable = [
        'request_token',
        'calendar_id',
        'day_id',
        'user_id',
        'user_game_account_id',
        'game_server_id',
        'reward_inventory_grant_id',
        'reward_date',
        'user_email',
        'game_account_login',
        'items_snapshot',
        'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'calendar_id' => 'integer',
            'day_id' => 'integer',
            'user_id' => 'integer',
            'user_game_account_id' => 'integer',
            'game_server_id' => 'integer',
            'reward_inventory_grant_id' => 'integer',
            'reward_date' => 'date',
            'items_snapshot' => 'array',
            'claimed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DailyRewardCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(DailyRewardCalendar::class, 'calendar_id');
    }

    /** @return BelongsTo<DailyRewardDay, $this> */
    public function day(): BelongsTo
    {
        return $this->belongsTo(DailyRewardDay::class, 'day_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<UserGameAccount, $this> */
    public function gameAccount(): BelongsTo
    {
        return $this->belongsTo(UserGameAccount::class, 'user_game_account_id');
    }

    /** @return BelongsTo<GameServer, $this> */
    public function gameServer(): BelongsTo
    {
        return $this->belongsTo(GameServer::class);
    }

    /** @return BelongsTo<RewardInventoryGrant, $this> */
    public function rewardGrant(): BelongsTo
    {
        return $this->belongsTo(RewardInventoryGrant::class, 'reward_inventory_grant_id');
    }
}
