<?php

namespace KaevCMS\Modules\Donations\Http\Requests;

use App\Http\Requests\Admin\AdminFormRequest;

final class SaveDonationCurrencyRateRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currency_code' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/D'],
            'unit_price' => ['required', 'string', 'max:22', 'regex:/\A[0-9]{1,12}(?:[.,][0-9]{1,4})?\z/D'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
