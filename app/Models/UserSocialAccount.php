<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserSocialAccount extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'provider', 'provider_user_id'];

    protected function casts(): array
    {
        return ['linked_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
