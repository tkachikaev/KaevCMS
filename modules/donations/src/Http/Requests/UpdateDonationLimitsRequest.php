<?php

namespace KaevCMS\Modules\Donations\Http\Requests;

use App\Http\Requests\Admin\AdminFormRequest;

final class UpdateDonationLimitsRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'min_coins' => ['required', 'integer', 'min:1', 'max:1000000'],
            'max_coins' => ['required', 'integer', 'min:1', 'max:1000000', 'gte:min_coins'],
        ];
    }
}
