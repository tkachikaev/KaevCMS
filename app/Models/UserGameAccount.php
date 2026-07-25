<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $login_server_id
 * @property int|null $registration_game_server_id
 * @property string $game_login
 * @property string $normalized_login
 * @property bool $created_via_cms
 * @property-read User $user
 * @property-read LoginServer $loginServer
 * @property-read GameServer|null $registrationGameServer
 */
class UserGameAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'login_server_id',
        'registration_game_server_id',
        'game_login',
        'normalized_login',
        'created_via_cms',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'login_server_id' => 'integer',
            'registration_game_server_id' => 'integer',
            'created_via_cms' => 'boolean',
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
}
