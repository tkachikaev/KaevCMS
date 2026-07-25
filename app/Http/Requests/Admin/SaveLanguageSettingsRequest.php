<?php

namespace App\Http\Requests\Admin;

use App\Services\Localization\LanguageManager;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SaveLanguageSettingsRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $installed = array_keys(app(LanguageManager::class)->installed());

        return [
            'enabled_locales' => ['required', 'array', 'min:1'],
            'enabled_locales.*' => ['required', 'string', Rule::in($installed)],
            'default_locale' => ['required', 'string', Rule::in($installed)],
            'fallback_locale' => ['required', 'string', Rule::in($installed)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $enabled = array_map('strval', (array) $this->input('enabled_locales', []));
            $default = (string) $this->input('default_locale');
            $fallback = (string) $this->input('fallback_locale');

            if ($default !== '' && ! in_array($default, $enabled, true)) {
                $validator->errors()->add('default_locale', __('The default language must be enabled.'));
            }

            if ($fallback !== '' && ! in_array($fallback, $enabled, true)) {
                $validator->errors()->add('fallback_locale', __('The fallback language must be enabled.'));
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'enabled_locales.required' => __('Enable at least one installed language.'),
            'enabled_locales.min' => __('Enable at least one installed language.'),
            'enabled_locales.*.in' => __('One of the selected languages is not installed.'),
            'default_locale.in' => __('Select an installed default language.'),
            'fallback_locale.in' => __('Select an installed fallback language.'),
        ];
    }
}
