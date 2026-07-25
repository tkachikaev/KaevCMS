<?php

namespace KaevCMS\Modules\PromoCodes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use KaevCMS\Modules\PromoCodes\Models\PromoCode;

final class ActivatePromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => PromoCode::normalizeCode((string) $this->input('code')),
            'request_token' => trim((string) $this->input('request_token')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:4', 'max:64', 'regex:/\A[A-Z0-9][A-Z0-9_-]{3,63}\z/'],
            'request_token' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => __('module-promo-codes::messages.attribute_code'),
            'request_token' => __('module-promo-codes::messages.attribute_request_token'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => __('module-promo-codes::messages.validation_required'),
            'min' => __('module-promo-codes::messages.validation_min'),
            'max' => __('module-promo-codes::messages.validation_max'),
            'regex' => __('module-promo-codes::messages.validation_code_format'),
            'uuid' => __('module-promo-codes::messages.validation_request_token'),
        ];
    }
}
