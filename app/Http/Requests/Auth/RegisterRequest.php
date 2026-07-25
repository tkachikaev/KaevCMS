<?php

namespace App\Http\Requests\Auth;

use App\Rules\PasswordWithinHasherLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

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
        return [
            'name' => ['required', 'string', 'min:3', 'max:32', 'alpha_dash:ascii', 'unique:users,name'],
            'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers(), new PasswordWithinHasherLimit],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => __('Enter a username.'),
            'name.min' => __('The username must be at least 3 characters.'),
            'name.max' => __('The username must not exceed 32 characters.'),
            'name.alpha_dash' => __('The username may contain Latin letters, digits, hyphens and underscores.'),
            'name.unique' => __('This username is already taken.'),
            'email.required' => __('Enter an email address.'),
            'email.email' => __('The email address is invalid.'),
            'email.unique' => __('This email address is already in use.'),
            'password.required' => __('Enter a password.'),
            'password.string' => __('The password must be a string.'),
            'password.min' => __('The password must be at least 8 characters.'),
            'password.letters' => __('The password must contain at least one letter.'),
            'password.numbers' => __('The password must contain at least one digit.'),
            'password.confirmed' => __('The passwords do not match.'),
        ];
    }
}
