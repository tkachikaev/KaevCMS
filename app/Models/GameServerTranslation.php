<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $game_server_id
 * @property string $locale
 * @property string $name
 * @property string|null $maintenance_message
 * @property-read GameServer $gameServer
 */
final class GameServerTranslation extends Model
{
    protected $fillable = ['game_server_id', 'locale', 'name', 'maintenance_message'];

    /** @return BelongsTo<GameServer, $this> */
    public function gameServer(): BelongsTo
    {
        return $this->belongsTo(GameServer::class);
    }
}
