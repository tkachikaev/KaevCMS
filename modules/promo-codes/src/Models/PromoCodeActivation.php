<?php

namespace KaevCMS\Modules\PromoCodes\Models;

use App\Models\GameServer;
use App\Models\RewardInventoryGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $request_token
 * @property int $promo_code_id
 * @property int|null $user_id
 * @property int $game_server_id
 * @property int|null $reward_inventory_grant_id
 * @property string $code_snapshot
 * @property string $user_email
 * @property Carbon|null $activated_at
 * @property-read PromoCode $promoCode
 * @property-read User|null $user
 * @property-read GameServer $gameServer
 * @property-read RewardInventoryGrant|null $rewardGrant
 */
final class PromoCodeActivation extends Model
{
    protected $table = 'module_promo_code_activations';

    protected $fillable = [
        'request_token',
        'promo_code_id',
        'user_id',
        'game_server_id',
        'reward_inventory_grant_id',
        'code_snapshot',
        'user_email',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'promo_code_id' => 'integer',
            'user_id' => 'integer',
            'game_server_id' => 'integer',
            'reward_inventory_grant_id' => 'integer',
            'activated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PromoCode, $this> */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
