<?php

namespace KaevCMS\Modules\Donations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $provider_code
 * @property bool $enabled
 * @property string $mode
 * @property list<string>|null $currency_codes
 * @property string|null $credentials_ciphertext
 */
final class DonationProviderSetting extends Model
{
    protected $table = 'module_donation_provider_settings';

    protected $primaryKey = 'provider_code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected $hidden = ['credentials_ciphertext'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'currency_codes' => 'array'];
    }
}
