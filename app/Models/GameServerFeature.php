<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $game_server_id
 * @property string $feature_key
 * @property bool $enabled
 * @property array<string,mixed>|null $settings
 * @property-read GameServer $gameServer
 */
class GameServerFeature extends Model
{
    protected $fillable = [
        'game_server_id',
        'feature_key',
        'enabled',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'game_server_id' => 'integer',
            'enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    /** @return BelongsTo<GameServer, $this> */
    public function gameServer(): BelongsTo
    {
        return $this->belongsTo(GameServer::class);
    }
}
