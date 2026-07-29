<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordWithinHasherLimit;
use App\Services\RegistrationSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => Str::lower(trim((string) $this->input('name'))),
            'email' => Str::lower(trim((string) $this->input('email'))),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $settings = app(RegistrationSettings::class);
        $policy = $settings->values();

        return [
            'name' => [
                'required',
                'string',
                'min:'.$policy['username_min'],
                'max:'.$policy['username_max'],
                'regex:'.$settings->usernameValidationRule(),
                'unique:users,name',
            ],
            'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', $settings->passwordRule(), new PasswordWithinHasherLimit],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $settings = app(RegistrationSettings::class);
        $policy = $settings->values();

        return [
            'name.required' => __('Enter a username.'),
            'name.min' => __('The username must be at least :count characters.', ['count' => $policy['username_min']]),
            'name.max' => __('The username must not exceed :count characters.', ['count' => $policy['username_max']]),
            'name.regex' => implode(' ', $settings->usernameRequirements()),
            'name.unique' => __('This username is already taken.'),
            'email.required' => __('Enter an email address.'),
            'email.email' => __('The email address is invalid.'),
            'email.unique' => __('This email address is already in use.'),
            'password.required' => __('Enter a password.'),
            'password.string' => __('The password must be a string.'),
            'password.min' => __('The password must be at least :count characters.', ['count' => $policy['password_min']]),
            'password.letters' => __('The password must contain at least one letter.'),
            'password.mixed' => __('The password must contain uppercase and lowercase letters.'),
            'password.numbers' => __('The password must contain at least one digit.'),
            'password.symbols' => __('The password must contain at least one symbol.'),
            'password.confirmed' => __('The passwords do not match.'),
        ];
    }
}
