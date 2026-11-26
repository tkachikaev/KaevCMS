<?php

namespace KaevCMS\Modules\Voting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $provider_code
 * @property bool $enabled
 * @property bool $player_access_enabled
 * @property Carbon|null $intent_retry_at
 * @property string|null $project_id
 * @property string|null $api_key_ciphertext
 * @property string $pending_policy
 * @property int $credential_revision
 * @property Carbon|null $last_checked_at
 * @property string|null $last_check_result
 */
final class VotingSetting extends Model
{
    protected $table = 'module_voting_settings';

    protected $hidden = ['api_key_ciphertext'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'player_access_enabled' => 'boolean',
            'intent_retry_at' => 'datetime',
            'credential_revision' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }
}
