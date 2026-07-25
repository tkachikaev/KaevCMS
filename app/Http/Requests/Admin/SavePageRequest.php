<?php

namespace App\Http\Requests\Admin;

use App\Services\Localization\LanguageManager;
use Illuminate\Validation\Validator;

class SavePageRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        $translations = $this->input('translations');
        if (is_array($translations)) {
            foreach ($translations as $locale => $values) {
                if (! is_array($values)) {
                    continue;
                }

                $translations[$locale] = [
                    'title' => trim((string) ($values['title'] ?? '')),
                    'slug' => trim((string) ($values['slug'] ?? '')),
                    'body' => trim((string) ($values['body'] ?? '')),
                    'seo_title' => trim((string) ($values['seo_title'] ?? '')),
                    'seo_description' => trim((string) ($values['seo_description'] ?? '')),
                ];
            }
        }

        $this->merge([
            'translations' => $translations,
            'preview_locale' => trim((string) $this->input('preview_locale')),
            'is_published' => $this->boolean('is_published'),
            'show_in_header' => $this->boolean('show_in_header'),
            'show_in_footer' => $this->boolean('show_in_footer'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'translations' => ['required', 'array'],
            'preview_locale' => ['nullable', 'string'],
            'is_published' => ['required', 'boolean'],
            'show_in_header' => ['required', 'boolean'],
            'show_in_footer' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ];

        foreach (app(LanguageManager::class)->enabledCodes() as $locale) {
            $rules['translations.'.$locale.'.title'] = ['nullable', 'string', 'max:255'];
            $rules['translations.'.$locale.'.slug'] = ['nullable', 'string', 'max:160', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'];
            $rules['translations.'.$locale.'.body'] = ['nullable', 'string', 'max:200000'];
            $rules['translations.'.$locale.'.seo_title'] = ['nullable', 'string', 'max:255'];
            $rules['translations.'.$locale.'.seo_description'] = ['nullable', 'string', 'max:500'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $translations = $this->input('translations');
            if (! is_array($translations)) {
                return;
            }

            $languages = app(LanguageManager::class);
            $default = $languages->default();

            foreach ($languages->enabledCodes() as $locale) {
                $values = is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
                $title = trim((string) ($values['title'] ?? ''));
                $body = trim((string) ($values['body'] ?? ''));
                $slug = trim((string) ($values['slug'] ?? ''));
                $seoTitle = trim((string) ($values['seo_title'] ?? ''));
                $seoDescription = trim((string) ($values['seo_description'] ?? ''));
                $isEmpty = $title === '' && $body === '' && $slug === '' && $seoTitle === '' && $seoDescription === '';

                if ($locale !== $default && $isEmpty) {
                    continue;
                }

                if ($title === '') {
                    $validator->errors()->add('translations.'.$locale.'.title', __('Add a page title or leave this translation completely empty.'));
                }

                if ($body === '') {
                    $validator->errors()->add('translations.'.$locale.'.body', __('Add page text or leave this translation completely empty.'));
                }
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'translations' => __('Page translations validation attribute'),
            'is_published' => __('publication status'),
            'show_in_header' => __('header navigation visibility'),
            'show_in_footer' => __('footer navigation visibility'),
            'sort_order' => __('sort order'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => __('The :attribute field is required.'),
            'integer' => __('The :attribute field contains an invalid value.'),
            'boolean' => __('The :attribute field contains an invalid value.'),
            'max' => __('The :attribute field exceeds the allowed size or length.'),
            'min' => __('The :attribute field contains an invalid value.'),
            'regex' => __('The address may contain only lowercase Latin letters, numbers and hyphens.'),
        ];
    }
}
