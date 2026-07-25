<?php

namespace App\Http\Requests\Admin;

use App\Services\Admin\AdminPathSettings;

class SaveAdminPathRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'admin_path_suffix' => [
                'nullable',
                'string',
                'min:3',
                'max:'.AdminPathSettings::MAX_SUFFIX_LENGTH,
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'admin_path_suffix.min' => __('The administrator path suffix must contain at least 3 characters.'),
            'admin_path_suffix.max' => __('The administrator path suffix may not exceed :max characters.'),
            'admin_path_suffix.regex' => __('Use only lowercase Latin letters, numbers, and single hyphens.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $suffix = trim((string) $this->input('admin_path_suffix', ''));

        $this->merge([
            'admin_path_suffix' => $suffix === '' ? null : $suffix,
        ]);
    }
}
