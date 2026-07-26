<?php

namespace App\Http\Requests\Account;

use App\Rules\PasswordWithinHasherLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangeAccountPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:4096'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers(), new PasswordWithinHasherLimit],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => __('Enter your current password.'),
            'password.required' => __('Enter a new password.'),
            'password.string' => __('The password must be a string.'),
            'password.min' => __('The password must be at least 8 characters.'),
            'password.letters' => __('The password must contain at least one letter.'),
            'password.numbers' => __('The password must contain at least one digit.'),
            'password.confirmed' => __('The passwords do not match.'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return public_route('security.edit');
    }
}
