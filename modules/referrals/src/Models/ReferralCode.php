<?php

namespace KaevCMS\Modules\Referrals\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReferralCode extends Model
{
    protected $table = 'module_referral_codes';

    protected $fillable = [
        'user_id',
        'code',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
