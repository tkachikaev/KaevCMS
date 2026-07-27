<?php

namespace App\Models;

use App\Support\Rewards\RewardQueueDiagnostic;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $operation_uuid
 * @property string $request_token
 * @property int $user_id
 * @property int $game_server_id
 * @property int|null $user_game_account_id
 * @property int $character_id
 * @property string $character_name
 * @property string $account_login
 * @property string $status
 * @property string|null $failure_code
 * @property Carbon|null $requested_at
 * @property Carbon|null $queued_at
 * @property-read User $user
 * @property-read GameServer $gameServer
 * @property-read UserGameAccount|null $gameAccount
 * @property-read EloquentCollection<int, RewardDeliveryItem> $items
 */
class RewardDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVIEW = 'review';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUEUED,
        self::STATUS_FAILED,
        self::STATUS_REVIEW,
    ];

    /** @var list<string> */
    public const RECONCILABLE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_REVIEW,
    ];

    /** @var array<string,list<string>> */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_QUEUED, self::STATUS_FAILED, self::STATUS_REVIEW],
        self::STATUS_REVIEW => [self::STATUS_QUEUED, self::STATUS_FAILED, self::STATUS_REVIEW],
        self::STATUS_QUEUED => [],
        self::STATUS_FAILED => [],
    ];

    protected $fillable = [
        'operation_uuid',
        'request_token',
        'user_id',
        'game_server_id',
        'user_game_account_id',
        'character_id',
        'character_name',
        'account_login',
        'status',
        'failure_code',
        'requested_at',
        'queued_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'game_server_id' => 'integer',
            'user_game_account_id' => 'integer',
            'character_id' => 'integer',
            'requested_at' => 'datetime',
            'queued_at' => 'datetime',
        ];
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

    /** @return BelongsTo<UserGameAccount, $this> */
    public function gameAccount(): BelongsTo
    {
        return $this->belongsTo(UserGameAccount::class, 'user_game_account_id');
    }

    /** @return HasMany<RewardDeliveryItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RewardDeliveryItem::class);
    }

    public function canReconcile(): bool
    {
        return in_array($this->status, self::RECONCILABLE_STATUSES, true);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    public function failureMessage(): string
    {
        return RewardQueueDiagnostic::messageFor($this->failure_code);
    }

    public function failureAction(): string
    {
        return RewardQueueDiagnostic::actionFor($this->failure_code);
    }

    public function statusLabel(): string
    {
        return self::statusLabelFor($this->status);
    }

    public static function statusLabelFor(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => __('rewards.status.pending.label'),
            self::STATUS_QUEUED => __('rewards.status.queued.label'),
            self::STATUS_FAILED => __('rewards.status.failed.label'),
            self::STATUS_REVIEW => __('rewards.status.review.label'),
            default => __('rewards.status.unknown.label'),
        };
    }
}
