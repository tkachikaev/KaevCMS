<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $operation_uuid
 * @property int|null $user_id
 * @property int|null $game_server_id
 * @property int|null $user_game_account_id
 * @property int $character_id
 * @property string $character_name
 * @property string $account_login
 * @property string $location_name
 * @property int|null $old_x
 * @property int|null $old_y
 * @property int|null $old_z
 * @property int $target_x
 * @property int $target_y
 * @property int $target_z
 * @property string $status
 * @property string|null $failure_code
 * @property Carbon $requested_at
 * @property Carbon|null $completed_at
 */
class CharacterRescueOperation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'operation_uuid',
        'user_id',
        'game_server_id',
        'user_game_account_id',
        'character_id',
        'character_name',
        'account_login',
        'location_name',
        'old_x',
        'old_y',
        'old_z',
        'target_x',
        'target_y',
        'target_z',
        'status',
        'failure_code',
        'requested_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'game_server_id' => 'integer',
            'user_game_account_id' => 'integer',
            'character_id' => 'integer',
            'old_x' => 'integer',
            'old_y' => 'integer',
            'old_z' => 'integer',
            'target_x' => 'integer',
            'target_y' => 'integer',
            'target_z' => 'integer',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
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
}
