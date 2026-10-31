<?php

namespace KaevCMS\Modules\Referrals\Models;

use App\Models\GameServer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReferralServerSetting extends Model
{
    protected $table = 'module_referral_server_settings';

    protected $fillable = [
        'game_server_id',
        'enabled',
        'checks_paused',
        'inviter_rewards_enabled',
        'invitee_rewards_enabled',
        'max_rewarded_invitees',
    ];

    protected function casts(): array
    {
        return [
            'game_server_id' => 'integer',
            'enabled' => 'boolean',
            'checks_paused' => 'boolean',
            'inviter_rewards_enabled' => 'boolean',
            'invitee_rewards_enabled' => 'boolean',
            'max_rewarded_invitees' => 'integer',
        ];
    }

    /** @return BelongsTo<GameServer, $this> */
    public function gameServer(): BelongsTo
    {
        return $this->belongsTo(GameServer::class, 'game_server_id');
    }
}
