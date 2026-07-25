<?php

namespace App\Http\Requests\Account;

use App\Services\Account\AccountAvatarCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAccountAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'avatar_filename' => ['nullable', 'string', 'max:190'],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /** @return array<string,string> */
    public function attributes(): array
    {
        return [
            'avatar_filename' => __('Avatar'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $filename = $this->input('avatar_filename');

        if ($filename === null) {
            return;
        }

        if (is_string($filename)) {
            $filename = trim($filename);
            $this->merge(['avatar_filename' => $filename === '' ? null : $filename]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $filename = $this->input('avatar_filename');
            if ($filename === null || $filename === '') {
                return;
            }

            if (! is_string($filename) || ! app(AccountAvatarCatalog::class)->contains($filename)) {
                $validator->errors()->add('avatar_filename', __('The selected avatar is no longer available.'));
            }
        });
    }
}
