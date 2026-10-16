<?php

namespace KaevCMS\Modules\Donations\Http\Requests;

use App\Http\Requests\Admin\AdminFormRequest;
use Illuminate\Validation\Validator;

final class SaveDonationWorkspaceRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'snapshot' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'show_in_account' => ['required', 'boolean'],
            'min_coins' => ['required', 'integer', 'min:1', 'max:1000000'],
            'max_coins' => ['required', 'integer', 'gte:min_coins', 'max:1000000'],
            'rates' => ['sometimes', 'array', 'max:300'],
            'rates.*' => ['required', 'array:unit_price,enabled,remove'],
            'rates.*.remove' => ['required', 'boolean'],
            'rates.*.unit_price' => ['exclude_if:rates.*.remove,1', 'required', 'string', 'max:22'],
            'rates.*.enabled' => ['exclude_if:rates.*.remove,1', 'required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $rates = $this->input('rates', []);
            if (! is_array($rates)) {
                return;
            }
            foreach (array_keys($rates) as $code) {
                if (! is_string($code) || ! preg_match('/\A[A-Z]{3}\z/D', $code)) {
                    $validator->errors()->add('rates', __('module-donations::messages.invalid_currency'));
                }
            }
        });
    }
}
