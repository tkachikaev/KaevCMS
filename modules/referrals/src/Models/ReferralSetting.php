<?php

namespace KaevCMS\Modules\Referrals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $setting_key
 * @property bool $enabled
 * @property int $invite_ttl_days
 * @property int|null $updated_by_admin_id
 */
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
