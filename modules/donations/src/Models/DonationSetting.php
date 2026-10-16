<?php

namespace KaevCMS\Modules\Donations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property bool $show_in_account
 * @property int $min_coins
 * @property int $max_coins
 * @property int|null $updated_by_admin_id
 */
final class DonationSetting extends Model
{
    protected $table = 'module_donation_settings';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'show_in_account' => 'boolean',
            'min_coins' => 'integer',
            'max_coins' => 'integer',
            'updated_by_admin_id' => 'integer',
        ];
    }
}
