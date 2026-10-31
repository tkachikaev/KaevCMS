<?php

namespace KaevCMS\Modules\Referrals\Models;

use Illuminate\Database\Eloquent\Model;

final class ReferralSetting extends Model
{
    protected $table = 'module_referral_settings';

    protected $fillable = [
        'setting_key',
        'enabled',
        'invite_ttl_days',
        'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'invite_ttl_days' => 'integer',
        ];
    }


}
