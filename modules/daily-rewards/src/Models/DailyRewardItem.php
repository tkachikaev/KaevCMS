<?php

namespace KaevCMS\Modules\DailyRewards\Models;

use App\Models\GameServer;
use App\Services\GameAssets\GameItemCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $day_id
 * @property int $item_id
 * @property int $amount
 * @property int $sort_order
 * @property-read DailyRewardDay $day
 */
final class DailyRewardItem extends Model
{
    protected $table = 'module_daily_reward_items';

    protected $fillable = [
        'day_id',
        'item_id',
        'amount',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'day_id' => 'integer',
            'item_id' => 'integer',
            'amount' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<DailyRewardDay, $this> */
    public function day(): BelongsTo
    {
        return $this->belongsTo(DailyRewardDay::class, 'day_id');
    }

    public function displayName(GameServer|int|null $server): string
    {
        return app(GameItemCatalog::class)->displayName($server, $this->item_id);
    }
}
