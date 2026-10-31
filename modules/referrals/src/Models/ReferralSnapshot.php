<?php

namespace KaevCMS\Modules\Referrals\Models;

use App\Models\GameServer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReferralSnapshot extends Model
{
    protected $table = 'module_referral_snapshots';

    protected $fillable = [
        'invite_id',
        'game_server_id',
        'snapshotted_at',
    ];

    protected function casts(): array
    {
        return [
            'invite_id' => 'integer',
            'game_server_id' => 'integer',
            'snapshotted_at' => 'datetime',
        ];
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
}
