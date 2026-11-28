<?php

namespace KaevCMS\Modules\Voting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $project_id
 * @property string $event_id
 * @property string $vote_id
 * @property string $cursor
 * @property string $player_ref
 * @property string $status
 * @property int $status_version
 * @property string $voted_at
 * @property string $status_changed_at
 */
final class VotingEvent extends Model
{
    public $timestamps = false;

    protected $table = 'module_voting_events';

    protected $hidden = ['player_ref'];

    protected function casts(): array
    {
        return ['status_version' => 'integer'];
    }
}
