<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $view_mode
 * @property int $schema_version
 * @property list<int> $hidden_game_server_ids
 * @property list<int> $hidden_game_account_ids
 * @property-read User $user
 */
class UserCharacterPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'view_mode',
        'schema_version',
        'hidden_game_server_ids',
        'hidden_game_account_ids',
    ];

    protected $attributes = [
        'view_mode' => 'all',
        'schema_version' => 2,
        'hidden_game_server_ids' => '[]',
        'hidden_game_account_ids' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'schema_version' => 'integer',
            'hidden_game_server_ids' => 'array',
            'hidden_game_account_ids' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
