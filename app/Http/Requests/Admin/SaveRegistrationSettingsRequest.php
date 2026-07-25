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
                    __('Save the mail settings and send a successful test email first.')
                );
            }
        });
    }
}
