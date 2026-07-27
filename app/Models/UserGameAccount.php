<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $login_server_id
 * @property int|null $registration_game_server_id
 * @property string $game_login
 * @property string $normalized_login
 * @property bool $created_via_cms
 * @property string|null $creation_uuid
 * @property string $creation_status
 * @property string|null $creation_credential
 * @property string|null $creation_email
 * @property int $creation_attempts
 * @property string|null $creation_last_error
 * @property Carbon|null $creation_write_attempted_at
 * @property Carbon|null $creation_processing_at
 * @property Carbon|null $creation_last_checked_at
 * @property Carbon|null $creation_completed_at
 * @property-read User $user
 * @property-read LoginServer $loginServer
 * @property-read GameServer|null $registrationGameServer
 */
class UserGameAccount extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'login_server_id',
        'registration_game_server_id',
        'game_login',
        'normalized_login',
        'created_via_cms',
    ];

    protected $hidden = [
        'creation_credential',
        'creation_email',
    ];

    protected $attributes = [
        'creation_status' => self::STATUS_ACTIVE,
        'creation_attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'login_server_id' => 'integer',
            'registration_game_server_id' => 'integer',
            'created_via_cms' => 'boolean',
            'creation_credential' => 'encrypted',
            'creation_email' => 'encrypted',
            'creation_attempts' => 'integer',
            'creation_write_attempted_at' => 'datetime',
            'creation_processing_at' => 'datetime',
            'creation_last_checked_at' => 'datetime',
            'creation_completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<LoginServer, $this> */
    public function loginServer(): BelongsTo
    {
        return $this->belongsTo(LoginServer::class);
    }

    /** @return BelongsTo<GameServer, $this> */
    public function registrationGameServer(): BelongsTo
    {
        return $this->belongsTo(GameServer::class, 'registration_game_server_id');
    }

    public function isActive(): bool
    {
        return $this->creation_status === self::STATUS_ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->creation_status === self::STATUS_PENDING;
    }

    public function isFailed(): bool
    {
        return $this->creation_status === self::STATUS_FAILED;
    }
}
