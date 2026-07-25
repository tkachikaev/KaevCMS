<?php

namespace KaevCMS\Modules\DailyRewards\Models;

use App\Models\GameServer;
use App\Services\GameAssets\GameItemCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $calendar_id
 * @property int $day_number
 * @property bool $enabled
 * @property-read DailyRewardCalendar $calendar
 * @property-read Collection<int, DailyRewardItem> $items
 * @property-read Collection<int, DailyRewardClaim> $claims
 */
final class DailyRewardDay extends Model
{
    protected $table = 'module_daily_reward_days';

    protected $fillable = [
        'calendar_id',
        'day_number',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'calendar_id' => 'integer',
            'day_number' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<DailyRewardCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(DailyRewardCalendar::class, 'calendar_id');
    }

    /** @return HasMany<DailyRewardItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DailyRewardItem::class, 'day_id')->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<DailyRewardClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(DailyRewardClaim::class, 'day_id');
    }

    public function summary(GameServer $server): string
    {
        return $this->items
            ->map(static fn (DailyRewardItem $item): string => app(GameItemCatalog::class)->displayName($server, $item->item_id)
                .' × '.number_format($item->amount, 0, '.', ' '))
            ->implode(', ');
    }
}
