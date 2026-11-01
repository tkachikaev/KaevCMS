<?php

namespace KaevCMS\Modules\Referrals\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int $referral_code_id
 * @property int $inviter_user_id
 * @property int $invitee_user_id
 * @property Carbon|null $accepted_at
 * @property string $status
 * @property Carbon|null $frozen_at
 * @property string|null $frozen_reason
 * @property int|null $frozen_by_admin_id
 */
final class ReferralInvite extends Model
{
    protected $table = 'module_referral_invites';

    protected $attributes = ['status' => 'active'];

    protected $fillable = [
        'referral_code_id',
        'inviter_user_id',
        'invitee_user_id',
        'accepted_at',
        'status',
        'frozen_at',
        'frozen_reason',
        'frozen_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'referral_code_id' => 'integer',
            'inviter_user_id' => 'integer',
            'invitee_user_id' => 'integer',
            'accepted_at' => 'datetime',
            'frozen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReferralCode, $this> */
    public function code(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class, 'referral_code_id');
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_user_id');
    }
}
