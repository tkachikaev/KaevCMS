<?php

namespace KaevCMS\Modules\Voting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $project_id
 * @property int|null $user_id
 * @property int|null $user_game_account_id
 * @property int|null $game_server_id
 * @property string $player_ref
 * @property string|null $request_id
 * @property string|null $intent_id
 * @property int $credential_revision
 * @property Carbon|null $attempt_expires_at
 */
final class VotingBinding extends Model
{
    protected $table = 'module_voting_bindings';

    protected $attributes = [
        'credential_revision' => 0,
        'request_id' => null,
        'intent_id' => null,
        'attempt_expires_at' => null,
    ];

    protected $hidden = ['player_ref', 'request_id', 'intent_id'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'user_game_account_id' => 'integer',
            'game_server_id' => 'integer',
            'credential_revision' => 'integer',
            'attempt_expires_at' => 'datetime',
        ];
    }
}
