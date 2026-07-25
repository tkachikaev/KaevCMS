<?php

namespace App\Http\Requests\Admin;

use App\Services\Localization\LanguageManager;
use Illuminate\Validation\Validator;

class SaveNewsRequest extends AdminFormRequest
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
                    'excerpt' => trim((string) ($values['excerpt'] ?? '')),
                    'body' => trim((string) ($values['body'] ?? '')),
                ];
            }
        }

        $this->merge([
            'title' => trim((string) $this->input('title')),
            'excerpt' => trim((string) $this->input('excerpt')),
            'body' => trim((string) $this->input('body')),
            'translations' => $translations,
            'preview_locale' => trim((string) $this->input('preview_locale')),
            'is_published' => $this->boolean('is_published'),
            'remove_cover_image' => $this->boolean('remove_cover_image'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $languages = app(LanguageManager::class);
        $rules = [
            'title' => ['nullable', 'required_without:translations', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:1000'],
            'body' => ['nullable', 'required_without:translations', 'string', 'max:200000'],
            'translations' => ['nullable', 'array'],
            'preview_locale' => ['nullable', 'string'],
            'cover_image' => [
                'nullable',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
                'dimensions:max_width=6000,max_height=6000',
            ],
            'remove_cover_image' => ['required', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'is_published' => ['required', 'boolean'],
        ];

        foreach ($languages->enabledCodes() as $locale) {
            $rules['translations.'.$locale.'.title'] = ['nullable', 'string', 'max:255'];
            $rules['translations.'.$locale.'.excerpt'] = ['nullable', 'string', 'max:1000'];
            $rules['translations.'.$locale.'.body'] = ['nullable', 'string', 'max:200000'];
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
            $defaultValues = is_array($translations[$default] ?? null) ? $translations[$default] : [];

            if (trim((string) ($defaultValues['title'] ?? '')) === '') {
                $validator->errors()->add('translations.'.$default.'.title', __('The default language title is required.'));
            }

            if (trim((string) ($defaultValues['body'] ?? '')) === '') {
                $validator->errors()->add('translations.'.$default.'.body', __('The default language news text is required.'));
            }

            foreach ($languages->enabledCodes() as $locale) {
                $values = is_array($translations[$locale] ?? null) ? $translations[$locale] : [];
                $title = trim((string) ($values['title'] ?? ''));
                $body = trim((string) ($values['body'] ?? ''));
                $excerpt = trim((string) ($values['excerpt'] ?? ''));

                if ($locale === $default || ($title === '' && $body === '' && $excerpt === '')) {
                    continue;
                }

                if ($title === '') {
                    $validator->errors()->add('translations.'.$locale.'.title', __('Add a title or leave this translation completely empty.'));
                }

                if ($body === '') {
                    $validator->errors()->add('translations.'.$locale.'.body', __('Add news text or leave this translation completely empty.'));
                }
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'title' => __('Title validation attribute'),
            'excerpt' => __('Short description validation attribute'),
            'body' => __('News text validation attribute'),
            'cover_image' => __('cover image'),
            'remove_cover_image' => __('remove cover image'),
            'published_at' => __('publication date'),
            'is_published' => __('publication status'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => __('The :attribute field is required.'),
            'max' => __('The :attribute field exceeds the allowed size or length.'),
            'date' => __('The :attribute field contains an invalid date.'),
            'boolean' => __('The :attribute field contains an invalid value.'),
            'cover_image.image' => __('The cover must be an image.'),
            'cover_image.mimes' => __('Only JPG, PNG and WebP are allowed.'),
            'cover_image.dimensions' => __('The image dimensions must not exceed 6000×6000 pixels.'),
        ];
    }
}
