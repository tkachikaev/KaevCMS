<?php

namespace App\Http\Requests\Account;

use App\Rules\PasswordWithinHasherLimit;
use App\Services\RegistrationSettings;
use Illuminate\Foundation\Http\FormRequest;

class ChangeAccountPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $settings = app(RegistrationSettings::class);

        return [
            'current_password' => ['required', 'string', 'max:4096'],
            'password' => ['required', 'confirmed', $settings->passwordRule(), new PasswordWithinHasherLimit],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $settings = app(RegistrationSettings::class);
        $policy = $settings->values();

        return [
            'current_password.required' => __('Enter your current password.'),
            'password.required' => __('Enter a new password.'),
            'password.string' => __('The password must be a string.'),
            'password.min' => __('The password must be at least :count characters.', ['count' => $policy['password_min']]),
            'password.letters' => __('The password must contain at least one letter.'),
            'password.mixed' => __('The password must contain uppercase and lowercase letters.'),
            'password.numbers' => __('The password must contain at least one digit.'),
            'password.symbols' => __('The password must contain at least one symbol.'),
            'password.confirmed' => __('The passwords do not match.'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return public_route('security.edit');
    }
}
