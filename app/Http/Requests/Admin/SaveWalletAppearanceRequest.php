<?php

namespace App\Http\Requests\Admin;

use App\Services\Localization\LanguageManager;
use App\Services\Settings\SettingsImageStorage;
use Closure;
use Illuminate\Http\UploadedFile;

final class SaveWalletAppearanceRequest extends AdminFormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        $rules = [
            'symbol' => ['nullable', 'string', 'max:16'],
            'icon' => [
                'nullable', 'file', 'max:1024',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value instanceof UploadedFile && ($error = app(SettingsImageStorage::class)->validateUpload($value, 'wallet-coin')) !== null) {
                        $fail($error);
                    }
                },
            ],
            'remove_icon' => ['nullable', 'boolean'],
            'translations' => ['required', 'array'],
        ];
        foreach (array_unique(array_merge(['ru', 'en'], app(LanguageManager::class)->enabledCodes())) as $code) {
            $rules['translations.'.$code] = ['required', 'array'];
            foreach (['one', 'few', 'many'] as $form) {
                $rules['translations.'.$code.'.'.$form] = ['required', 'string', 'max:48'];
            }
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $translations = $this->input('translations');
        if (is_array($translations)) {
            foreach ($translations as $code => $forms) {
                if (! is_array($forms)) {
                    continue;
                }
                foreach (['one', 'few', 'many'] as $form) {
                    if (array_key_exists($form, $forms) && is_string($forms[$form])) {
                        $translations[$code][$form] = trim($forms[$form]);
                    }
                }
            }
        }
        $this->merge([
            'symbol' => is_string($this->input('symbol')) ? trim($this->input('symbol')) : $this->input('symbol'),
            'translations' => $translations,
        ]);
    }
}
