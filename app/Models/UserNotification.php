<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string|null $group_key
 * @property string $title_key
 * @property array<string, scalar>|null $parameters
 * @property string $url
 * @property int $occurrences
 * @property Carbon $occurred_at
 * @property Carbon|null $read_at
 */
class UserNotification extends Model
{
    protected $fillable = [
        'user_id', 'type', 'group_key', 'title_key', 'parameters', 'url', 'occurrences', 'occurred_at', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'occurrences' => 'integer',
            'occurred_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
