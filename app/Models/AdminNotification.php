<?php

namespace App\Models;

use App\Support\Notifications\AdminNotificationSeverity;
use App\Support\Notifications\AdminNotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * @property int $id
 * @property int $admin_id
 * @property AdminNotificationType $type
 * @property AdminNotificationSeverity $severity
 * @property string|null $external_key
 * @property string|null $deduplication_key
 * @property string $title_key
 * @property string|null $message_key
 * @property array<string, bool|float|int|string|null>|null $parameters
 * @property string|null $route_name
 * @property array<string, bool|int|string>|null $route_parameters
 * @property int $occurrences
 * @property Carbon $occurred_at
 * @property Carbon $last_occurred_at
 * @property Carbon|null $read_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $dismissed_at
 */
class AdminNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_id',
        'type',
        'severity',
        'external_key',
        'deduplication_key',
        'title_key',
        'message_key',
        'parameters',
        'route_name',
        'route_parameters',
        'occurrences',
        'occurred_at',
        'last_occurred_at',
        'read_at',
        'resolved_at',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => AdminNotificationType::class,
            'severity' => AdminNotificationSeverity::class,
            'parameters' => 'array',
            'route_parameters' => 'array',
            'occurrences' => 'integer',
            'occurred_at' => 'datetime',
            'last_occurred_at' => 'datetime',
            'read_at' => 'datetime',
            'resolved_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Admin, $this> */
    public function administrator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    /** @param Builder<AdminNotification> $query */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNull('dismissed_at');
    }

    /** @param Builder<AdminNotification> $query */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    public function title(): string
    {
        return $this->translate($this->title_key, __('Administration notification'));
    }

    public function message(): ?string
    {
        if (! is_string($this->message_key) || $this->message_key === '') {
            return null;
        }

        return $this->translate($this->message_key, null);
    }

    public function actionUrl(): ?string
    {
        if (! is_string($this->route_name)
            || $this->route_name === ''
            || ! $this->type->allowsRoute($this->route_name)
            || ! Route::has($this->route_name)) {
            return null;
        }

        try {
            return route($this->route_name, is_array($this->route_parameters) ? $this->route_parameters : []);
        } catch (Throwable) {
            return null;
        }
    }

    private function translate(string $key, ?string $fallback): ?string
    {
        if (! Lang::has($key)) {
            return $fallback;
        }

        $translated = __($key, is_array($this->parameters) ? $this->parameters : []);

        return is_string($translated) && $translated !== '' ? $translated : $fallback;
    }
}
