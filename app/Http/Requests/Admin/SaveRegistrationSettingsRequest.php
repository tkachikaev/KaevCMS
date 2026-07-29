<?php

namespace App\Http\Requests\Admin;

use App\Services\MailSettings;
use Illuminate\Validation\Validator;

class SaveRegistrationSettingsRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'registration_enabled' => ['nullable', 'boolean'],
            'email_verification_required' => ['nullable', 'boolean'],
            'username_min' => ['sometimes', 'required', 'integer', 'min:3', 'max:32'],
            'username_max' => ['sometimes', 'required', 'integer', 'min:3', 'max:64', 'gte:username_min'],
            'username_allow_hyphen' => ['sometimes', 'nullable', 'boolean'],
            'username_allow_underscore' => ['sometimes', 'nullable', 'boolean'],
            'password_min' => ['sometimes', 'required', 'integer', 'min:8', 'max:64'],
            'password_letters' => ['sometimes', 'nullable', 'boolean'],
            'password_mixed_case' => ['sometimes', 'nullable', 'boolean'],
            'password_numbers' => ['sometimes', 'nullable', 'boolean'],
            'password_symbols' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('registration_enabled') || ! $this->boolean('email_verification_required')) {
                return;
            }

            if (! app(MailSettings::class)->isReady()) {
                $validator->errors()->add(
                    'email_verification_required',
                    __('Save the mail settings and send a successful test email first.'),
                );
            }
        });
    }
}
