<?php

namespace App\Models;

use App\Services\Localization\LanguageManager;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $rates
 * @property string|null $chronicle
 * @property string|null $mode
 * @property int $sort_order
 * @property int|null $login_server_id
 * @property string|null $driver
 * @property bool $use_login_server_connection
 * @property string|null $database_host
 * @property int|null $database_port
 * @property string|null $database_name
 * @property string|null $database_username
 * @property string|null $database_password
 * @property string|null $database_charset
 * @property string|null $service_host
 * @property int|null $service_port
 * @property string $database_status
 * @property string|null $database_error
 * @property Carbon|null $database_checked_at
 * @property string $monitor_status
 * @property int $monitor_failures
 * @property Carbon|null $monitor_checked_at
 * @property Carbon|null $monitor_last_online_at
 * @property int|null $online_players
 * @property Carbon|null $online_checked_at
 * @property bool $maintenance_enabled
 * @property bool $statistics_enabled
 * @property bool $statistics_level_enabled
 * @property bool $statistics_pvp_enabled
 * @property bool $statistics_pk_enabled
 * @property bool $statistics_play_time_enabled
 * @property bool $statistics_heroes_enabled
 * @property bool $statistics_castles_enabled
 * @property int $statistics_level_limit
 * @property int $statistics_pvp_limit
 * @property int $statistics_pk_limit
 * @property int $statistics_play_time_limit
 * @property-read LoginServer|null $loginServer
 * @property-read Collection<int, GameServerTranslation> $translations
 */
class GameServer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'rates',
        'chronicle',
        'mode',
        'sort_order',
        'login_server_id',
        'driver',
        'use_login_server_connection',
        'database_host',
        'database_port',
        'database_name',
        'database_username',
        'database_password',
        'database_charset',
        'service_host',
        'service_port',
        'database_status',
        'database_error',
        'database_checked_at',
        'monitor_status',
        'monitor_failures',
        'monitor_checked_at',
        'monitor_last_online_at',
        'online_players',
        'online_checked_at',
        'maintenance_enabled',
        'statistics_enabled',
        'statistics_level_enabled',
        'statistics_pvp_enabled',
        'statistics_pk_enabled',
        'statistics_play_time_enabled',
        'statistics_heroes_enabled',
        'statistics_castles_enabled',
        'statistics_level_limit',
        'statistics_pvp_limit',
        'statistics_pk_limit',
        'statistics_play_time_limit',
    ];

    protected $hidden = [
        'database_password',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'login_server_id' => 'integer',
            'use_login_server_connection' => 'boolean',
            'database_port' => 'integer',
            'database_password' => 'encrypted',
            'service_port' => 'integer',
            'database_checked_at' => 'datetime',
            'monitor_failures' => 'integer',
            'monitor_checked_at' => 'datetime',
            'monitor_last_online_at' => 'datetime',
            'online_players' => 'integer',
            'online_checked_at' => 'datetime',
            'maintenance_enabled' => 'boolean',
            'statistics_enabled' => 'boolean',
            'statistics_level_enabled' => 'boolean',
            'statistics_pvp_enabled' => 'boolean',
            'statistics_pk_enabled' => 'boolean',
            'statistics_play_time_enabled' => 'boolean',
            'statistics_heroes_enabled' => 'boolean',
            'statistics_castles_enabled' => 'boolean',
            'statistics_level_limit' => 'integer',
            'statistics_pvp_limit' => 'integer',
            'statistics_pk_limit' => 'integer',
            'statistics_play_time_limit' => 'integer',
        ];
    }

    /** @return BelongsTo<LoginServer, $this> */
    public function loginServer(): BelongsTo
    {
        return $this->belongsTo(LoginServer::class);
    }

    /** @return HasMany<RewardInventoryGrant, $this> */
    public function rewardInventoryGrants(): HasMany
    {
        return $this->hasMany(RewardInventoryGrant::class);
    }

    /** @return HasMany<RewardInventoryItem, $this> */
    public function rewardInventoryItems(): HasMany
    {
        return $this->hasMany(RewardInventoryItem::class);
    }

    /** @return HasMany<RewardDelivery, $this> */
    public function rewardDeliveries(): HasMany
    {
        return $this->hasMany(RewardDelivery::class);
    }

    public function databasePassword(): ?string
    {
        try {
            return is_string($this->database_password) ? $this->database_password : null;
        } catch (DecryptException) {
            return null;
        }
    }

    public function hasDatabasePassword(): bool
    {
        $value = $this->getRawOriginal('database_password');

        return is_string($value) && $value !== '';
    }

    public function databaseVerified(): bool
    {
        return $this->database_status === 'configured' && $this->database_checked_at !== null;
    }

    public function connectionConfigured(): bool
    {
        if ($this->driver === null || $this->login_server_id === null) {
            return false;
        }

        if ($this->use_login_server_connection) {
            return true;
        }

        return trim((string) $this->database_host) !== ''
            && $this->database_port !== null
            && trim((string) $this->database_name) !== ''
            && trim((string) $this->database_username) !== ''
            && trim((string) $this->database_charset) !== '';
    }

    /** @return HasMany<GameServerTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(GameServerTranslation::class);
    }

    public function nameFor(?string $locale = null, bool $withFallback = true): string
    {
        $locale ??= app()->getLocale();
        $languages = app(LanguageManager::class);
        $locale = $languages->normalizeCode($locale) ?? $languages->default();

        if (! $this->translationsTableExists()) {
            return trim((string) $this->name);
        }

        $candidates = $withFallback
            ? $languages->fallbackCandidates($locale)
            : [$locale];

        $translations = $this->relationLoaded('translations')
            ? $this->getRelation('translations')
            : $this->translations()->whereIn('locale', $candidates)->get();

        if ($translations instanceof Collection) {
            foreach ($candidates as $candidate) {
                $translation = $translations->firstWhere('locale', $candidate);
                if ($translation instanceof GameServerTranslation && trim((string) $translation->name) !== '') {
                    return trim((string) $translation->name);
                }
            }
        }

        return trim((string) $this->name);
    }

    public function maintenanceMessageFor(?string $locale = null, bool $withFallback = true): string
    {
        $locale ??= app()->getLocale();
        $languages = app(LanguageManager::class);
        $locale = $languages->normalizeCode($locale) ?? $languages->default();

        if (! $this->translationsTableExists()) {
            return '';
        }

        $candidates = $withFallback
            ? $languages->fallbackCandidates($locale)
            : [$locale];

        $translations = $this->relationLoaded('translations')
            ? $this->getRelation('translations')
            : $this->translations()->whereIn('locale', $candidates)->get();

        if ($translations instanceof Collection) {
            foreach ($candidates as $candidate) {
                $translation = $translations->firstWhere('locale', $candidate);
                if ($translation instanceof GameServerTranslation
                    && trim((string) $translation->maintenance_message) !== '') {
                    return trim((string) $translation->maintenance_message);
                }
            }
        }

        return '';
    }

    private function translationsTableExists(): bool
    {
        try {
            return Schema::hasTable('game_server_translations');
        } catch (Throwable) {
            return false;
        }
    }
}
