<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Validator;

/** Explicit, integer-only wallet adjustment input. Never trust a client-supplied amount or source. */
final class AdjustWalletRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operation' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
            'reason' => ['required', 'string', 'min:3', 'max:190'],
            'operation_key' => ['required', 'uuid'],
            'confirm_adjustment' => ['required', 'accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $amount = $this->input('amount');
            if (! is_string($amount) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $amount) !== 1) {
                return;
            }
            $max = (string) PHP_INT_MAX;
            if (strlen($amount) > strlen($max) || (strlen($amount) === strlen($max) && strcmp($amount, $max) > 0)) {
                $validator->errors()->add('amount', __('Wallet amount is too large.'));
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
