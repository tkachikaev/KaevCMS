<?php

namespace KaevCMS\Modules\DailyRewards\Models;

use App\Models\Admin;
use App\Models\GameServer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $game_server_id
 * @property int $year
 * @property int $month
 * @property string $timezone
 * @property bool $enabled
 * @property int|null $created_by_admin_id
 * @property int|null $updated_by_admin_id
 * @property-read GameServer $gameServer
 * @property-read Collection<int, DailyRewardDay> $days
 * @property-read Collection<int, DailyRewardClaim> $claims
 */
final class DailyRewardCalendar extends Model
{
    protected $table = 'module_daily_reward_calendars';

    protected $fillable = [
        'game_server_id',
        'year',
        'month',
        'timezone',
        'enabled',
        'created_by_admin_id',
        'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'game_server_id' => 'integer',
            'year' => 'integer',
            'month' => 'integer',
            'enabled' => 'boolean',
            'created_by_admin_id' => 'integer',
            'updated_by_admin_id' => 'integer',
        ];
    }

    /** @return BelongsTo<GameServer, $this> */
    public function gameServer(): BelongsTo
    {
        return $this->belongsTo(GameServer::class);
    }

    /** @return BelongsTo<Admin, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /** @return BelongsTo<Admin, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }

    /** @return HasMany<DailyRewardDay, $this> */
    public function days(): HasMany
    {
        return $this->hasMany(DailyRewardDay::class, 'calendar_id')->orderBy('day_number');
    }

    /** @return HasMany<DailyRewardClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(DailyRewardClaim::class, 'calendar_id');
    }

    public function runtimeTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    public function daysInMonth(): int
    {
        return Carbon::create($this->year, $this->month, 1, 0, 0, 0, $this->runtimeTimezone())->daysInMonth;
    }

    public function periodLabel(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return Carbon::create($this->year, $this->month, 1, 0, 0, 0, $this->runtimeTimezone())
            ->locale($locale)
            ->translatedFormat('F Y');
    }
}
