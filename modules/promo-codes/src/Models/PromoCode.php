<?php

namespace KaevCMS\Modules\PromoCodes\Models;

use App\Models\Admin;
use App\Models\GameServer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $game_server_id
 * @property string $code
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int $total_limit
 * @property int $per_user_limit
 * @property int $activations_count
 * @property bool $enabled
 * @property int|null $created_by_admin_id
 * @property int|null $updated_by_admin_id
 * @property Carbon|null $deleted_at
 * @property-read GameServer $gameServer
 * @property-read Admin|null $creator
 * @property-read Admin|null $updater
 * @property-read Collection<int, PromoCodeReward> $rewards
 * @property-read Collection<int, PromoCodeActivation> $activations
 */
final class PromoCode extends Model
{
    use SoftDeletes;

    protected $table = 'module_promo_codes';

    protected $fillable = [
        'game_server_id',
        'code',
        'starts_at',
        'ends_at',
        'total_limit',
        'per_user_limit',
        'activations_count',
        'enabled',
        'created_by_admin_id',
        'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'game_server_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'total_limit' => 'integer',
            'per_user_limit' => 'integer',
            'activations_count' => 'integer',
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

    /** @return HasMany<PromoCodeReward, $this> */
    public function rewards(): HasMany
    {
        return $this->hasMany(PromoCodeReward::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<PromoCodeActivation, $this> */
    public function activations(): HasMany
    {
        return $this->hasMany(PromoCodeActivation::class);
    }

    public static function normalizeCode(string $code): string
    {
        return Str::upper(trim($code));
    }

    public function availabilityCode(?Carbon $at = null): string
    {
        $at ??= now();

        if (! $this->enabled) {
            return 'disabled';
        }

        if ($this->starts_at instanceof Carbon && $this->starts_at->isAfter($at)) {
            return 'scheduled';
        }

        if ($this->ends_at instanceof Carbon && $this->ends_at->isBefore($at)) {
            return 'expired';
        }

        if ($this->total_limit > 0 && $this->activations_count >= $this->total_limit) {
            return 'exhausted';
        }

        return 'active';
    }

    public function availabilityLabel(): string
    {
        return match ($this->availabilityCode()) {
            'disabled' => __('module-promo-codes::messages.status_disabled'),
            'scheduled' => __('module-promo-codes::messages.status_scheduled'),
            'expired' => __('module-promo-codes::messages.status_expired'),
            'exhausted' => __('module-promo-codes::messages.status_exhausted'),
            default => __('module-promo-codes::messages.status_active'),
        };
    }
}
