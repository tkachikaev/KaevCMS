<?php

namespace App\Http\Requests\Account;

use App\Models\User;
use App\Rules\PlayerDisplayName;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateDisplayNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['display_name' => ['present', 'nullable', 'string', 'min:3', 'max:24', new PlayerDisplayName,
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && User::query()->where('display_name_key', PlayerDisplayName::key($value))
                    ->whereKeyNot($this->user()?->getAuthIdentifier())->exists()) {
                    $fail(__('This display name is already taken.'));
                }
            },
        ]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['display_name' => __('Display name')];
    }
}
