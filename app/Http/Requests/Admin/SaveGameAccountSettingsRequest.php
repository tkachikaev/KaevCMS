<?php

namespace App\Http\Requests\Admin;

class SaveGameAccountSettingsRequest extends AdminFormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'creation_enabled' => ['nullable', 'boolean'],
            'max_accounts' => ['required', 'integer', 'between:1,50'],
            'login_min' => ['required', 'integer', 'between:1,45'],
            'login_max' => ['required', 'integer', 'between:1,45', 'gte:login_min'],
            'login_digit' => ['nullable', 'boolean'],
            'password_min' => ['required', 'integer', 'between:1,45'],
            'password_max' => ['required', 'integer', 'between:1,45', 'gte:password_min'],
            'password_lower' => ['nullable', 'boolean'],
            'password_upper' => ['nullable', 'boolean'],
            'password_digit' => ['nullable', 'boolean'],
        ];
    }
}
