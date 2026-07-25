<?php

namespace App\Http\Requests\Admin;

use App\Services\Localization\LanguageManager;
use App\Services\Settings\SettingsImageStorage;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class SaveGeneralSettingsRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $languages = app(LanguageManager::class);
        $rules = [
            'site_name' => ['nullable', 'required_without:translations', 'string', 'max:100'],
            'site_description' => ['nullable', 'string', 'max:255'],
            'footer_text' => ['nullable', 'string', 'max:255'],
            'translations' => ['nullable', 'array'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'admin_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'show_public_online' => ['sometimes', 'boolean'],
            'logo' => [
                'nullable',
                'file',
                'max:5120',
                $this->imageRule('logo'),
            ],
            'favicon' => [
                'nullable',
                'file',
                'max:1024',
                $this->imageRule('favicon'),
            ],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_favicon' => ['nullable', 'boolean'],
        ];

        foreach ($languages->enabledCodes() as $locale) {
            $nameRules = ['nullable', 'string', 'max:100'];
            if ($locale === $languages->default()) {
                array_splice($nameRules, 1, 0, ['required_with:translations']);
            }

            $rules['translations.'.$locale.'.name'] = $nameRules;
            $rules['translations.'.$locale.'.description'] = ['nullable', 'string', 'max:255'];
            $rules['translations.'.$locale.'.footer_text'] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'site_name' => __('Site name validation attribute'),
            'site_description' => __('Short description validation attribute'),
            'timezone' => __('Time zone validation attribute'),
            'admin_email' => __('Administrator email validation attribute'),
            'show_public_online' => __('Show public online validation attribute'),
            'footer_text' => __('Footer text validation attribute'),
            'logo' => __('Logo validation attribute'),
            'favicon' => 'favicon',
        ];
    }

    protected function prepareForValidation(): void
    {
        $translations = $this->input('translations');
        if (is_array($translations)) {
            foreach ($translations as $locale => $values) {
                if (! is_array($values)) {
                    continue;
                }

                $translations[$locale] = [
                    'name' => trim((string) ($values['name'] ?? '')),
                    'description' => trim((string) ($values['description'] ?? '')),
                    'footer_text' => trim((string) ($values['footer_text'] ?? '')),
                ];
            }
        }

        $this->merge([
            'site_name' => trim((string) $this->input('site_name')),
            'site_description' => trim((string) $this->input('site_description')),
            'timezone' => trim((string) $this->input('timezone')),
            'admin_email' => trim((string) $this->input('admin_email')),
            ...($this->has('show_public_online')
                ? ['show_public_online' => $this->boolean('show_public_online')]
                : []),
            'footer_text' => trim((string) $this->input('footer_text')),
            'translations' => $translations,
            'remove_logo' => $this->boolean('remove_logo'),
            'remove_favicon' => $this->boolean('remove_favicon'),
        ]);
    }

    private function imageRule(string $kind): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($kind): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $error = app(SettingsImageStorage::class)->validateUpload($value, $kind);
            if ($error !== null) {
                $fail($error);
            }
        };
    }
}
