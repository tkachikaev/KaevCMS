<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class SaveMailSettingsRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:1024'],
            'from_address' => ['required', 'email:rfc', 'max:255'],
            'from_name' => ['required', 'string', 'max:100'],
            'notification_email' => ['nullable', 'email:rfc', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'smtp_host.required' => __('Enter the SMTP server.'),
            'smtp_port.required' => __('Enter the SMTP server port.'),
            'smtp_port.between' => __('The port must be between 1 and 65535.'),
            'from_address.required' => __('Enter the sender email address.'),
            'from_address.email' => __('The sender email address is invalid.'),
            'from_name.required' => __('Enter the sender name.'),
            'notification_email.email' => __('The system notification email is invalid.'),
        ];
    }
}
