<?php

namespace App\Http\Requests\Admin;

class SendTestMailRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'test_email' => ['required', 'email:rfc', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'test_email.required' => __('Enter an address for the test email.'),
            'test_email.email' => __('The test email address is invalid.'),
        ];
    }
}
