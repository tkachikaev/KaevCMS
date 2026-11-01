<?php

namespace KaevCMS\Modules\Referrals\Models;

use App\Models\GameServer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int $invite_id
 * @property int $game_server_id
 * @property int $max_seen_level
 * @property string $status
 * @property Carbon|null $next_check_at
 * @property int $consecutive_failures
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $last_success_at
 * @property string|null $last_error_code
 */
final class ReferralProgress extends Model
{
    protected $table = 'module_referral_progress';

    protected $fillable = [
        'invite_id',
        'game_server_id',
        'max_seen_level',
        'status',
        'next_check_at',
        'consecutive_failures',
        'last_checked_at',
        'last_success_at',
        'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'invite_id' => 'integer',
            'game_server_id' => 'integer',
            'max_seen_level' => 'integer',
            'next_check_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
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
