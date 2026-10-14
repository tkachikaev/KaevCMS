<?php

namespace App\Http\Requests\Admin;

use App\Models\NavigationLink;
use App\Services\Localization\LanguageManager;
use Closure;
use Illuminate\Validation\Validator;

class SaveNavigationLinkRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        $translations = $this->input('translations');
        if (is_array($translations)) {
            foreach ($translations as $locale => $values) {
                if (is_array($values)) {
                    if (is_string($values['title'] ?? null)) {
                        $translations[$locale]['title'] = trim($values['title']);
                    }
                }
            }
        }

        $this->merge([
            'translations' => $translations,
            'url' => is_string($this->input('url')) ? trim($this->input('url')) : $this->input('url'),
            'is_active' => $this->boolean('is_active'),
            'show_in_header' => $this->boolean('show_in_header'),
            'show_in_footer' => $this->boolean('show_in_footer'),
            'open_new_tab' => $this->boolean('open_new_tab'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'url' => [
                'required',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! NavigationLink::isSafeUrl($value)) {
                        $fail(__('Use a site path starting with / or a full http(s) URL.'));
                    }
                },
            ],
            'translations' => ['required', 'array'],
            'is_active' => ['required', 'boolean'],
            'show_in_header' => ['required', 'boolean'],
            'show_in_footer' => ['required', 'boolean'],
            'open_new_tab' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ];

        foreach (app(LanguageManager::class)->enabledCodes() as $locale) {
            $rules['translations.'.$locale.'.title'] = ['nullable', 'string', 'max:160'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $translations = $this->input('translations');
            $default = app(LanguageManager::class)->default();
            if (! is_array($translations) || trim((string) ($translations[$default]['title'] ?? '')) === '') {
                $validator->errors()->add('translations.'.$default.'.title', __('A title is required in the default language.'));
            }

            if (! $this->boolean('show_in_header') && ! $this->boolean('show_in_footer')) {
                $validator->errors()->add('show_in_header', __('Select header, footer, or both.'));
            }
        });
    }
}
