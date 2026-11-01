<?php

namespace KaevCMS\Modules\Referrals\Models;

use App\Models\GameServer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int $game_server_id
 * @property bool $enabled
 * @property bool $checks_paused
 * @property bool $inviter_rewards_enabled
 * @property bool $invitee_rewards_enabled
 * @property int|null $max_rewarded_invitees
 */
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

    /**
     * A participating server must have a rule for an enabled recipient side.
     * Shared by account navigation and the player dashboard.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAvailableForInvitations(Builder $query): Builder
    {
        return $query->where('enabled', true)->where(static function (Builder $query): void {
            foreach (['inviter', 'invitee'] as $audience) {
                $method = $audience === 'inviter' ? 'where' : 'orWhere';
                $query->{$method}(static function (Builder $side) use ($audience): void {
                    $side->where($audience.'_rewards_enabled', true)
                        ->whereIn('game_server_id', ReferralRule::query()
                            ->select('game_server_id')->where('enabled', true)->where('audience', $audience));
                });
            }
        });
    }

    /** @return BelongsTo<GameServer, $this> */
    public function gameServer(): BelongsTo
    {
        return $this->belongsTo(GameServer::class, 'game_server_id');
    }
}
