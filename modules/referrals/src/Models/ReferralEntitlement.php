<?php

namespace KaevCMS\Modules\Referrals\Models;

use App\Models\GameServer;
use App\Models\RewardInventoryGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReferralEntitlement extends Model
{
    protected $table = 'module_referral_entitlements';

    protected $fillable = [
        'snapshot_id',
        'invite_id',
        'game_server_id',
        'recipient_user_id',
        'audience',
        'level',
        'item_id',
        'amount',
        'status',
        'reward_inventory_grant_id',
        'earned_at',
        'granted_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_id' => 'integer',
            'invite_id' => 'integer',
            'game_server_id' => 'integer',
            'recipient_user_id' => 'integer',
            'level' => 'integer',
            'item_id' => 'integer',
            'amount' => 'integer',
            'reward_inventory_grant_id' => 'integer',
            'earned_at' => 'datetime',
            'granted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReferralSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ReferralSnapshot::class, 'snapshot_id');
    }

    /** @return BelongsTo<ReferralInvite, $this> */
    public function invite(): BelongsTo
    {
        return $this->belongsTo(ReferralInvite::class, 'invite_id');
    }

    /** @return BelongsTo<GameServer, $this> */
    public function gameServer(): BelongsTo
    {
        return $this->belongsTo(GameServer::class, 'game_server_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /** @return BelongsTo<RewardInventoryGrant, $this> */
    public function inventoryGrant(): BelongsTo
    {
        return $this->belongsTo(RewardInventoryGrant::class, 'reward_inventory_grant_id');
    }
}
