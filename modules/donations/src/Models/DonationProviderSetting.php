<?php

namespace KaevCMS\Modules\Donations\Models;

use Illuminate\Database\Eloquent\Model;

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
