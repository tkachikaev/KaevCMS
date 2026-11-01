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
 * @property int $game_server_id
 * @property string $audience
 * @property int $level
 * @property int $item_id
 * @property int $amount
 * @property bool $enabled
 */
final class ReferralRule extends Model
{
    protected $table = 'module_referral_rules';

    protected $fillable = [
        'game_server_id',
        'audience',
        'level',
        'item_id',
        'amount',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'game_server_id' => 'integer',
            'level' => 'integer',
            'item_id' => 'integer',
            'amount' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<GameServer, $this> */
    public function gameServer(): BelongsTo
    {
        return $this->belongsTo(GameServer::class, 'game_server_id');
    }
}
