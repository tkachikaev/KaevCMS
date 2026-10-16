<?php

namespace KaevCMS\Modules\Donations\Models;

use Illuminate\Database\Eloquent\Model;

/** @property string $currency_code @property int $exponent @property int $unit_price_minor @property bool $enabled */
final class DonationCurrencyRate extends Model
{
    protected $table = 'module_donation_currency_rates';

    protected $primaryKey = 'currency_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['exponent' => 'integer', 'unit_price_minor' => 'integer', 'enabled' => 'boolean', 'updated_by_admin_id' => 'integer'];
    }
}
