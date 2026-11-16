<?php

namespace App\Http\Requests\Admin;

use App\Services\NewsDisplaySettings;
use App\Services\Settings\SettingsImageStorage;
use Closure;
use Illuminate\Http\UploadedFile;

class SaveNewsDisplaySettingsRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        [, $homeMin, $homeMax] = NewsDisplaySettings::HOME_LIMIT;
        [, $pageMin, $pageMax] = NewsDisplaySettings::PER_PAGE;

        return [
            'home_limit' => ['required', 'integer', 'between:'.$homeMin.','.$homeMax],
            'per_page' => ['required', 'integer', 'between:'.$pageMin.','.$pageMax],
            'default_cover' => [
                'nullable',
                'file',
                'max:5120',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $error = app(SettingsImageStorage::class)->validateUpload($value, 'news-cover');
                    if ($error !== null) {
                        $fail($error);
                    }
                },
            ],
            'remove_default_cover' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'home_limit' => __('News on the home page'),
            'per_page' => __('News per page'),
            'default_cover' => __('Default cover'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['remove_default_cover' => $this->boolean('remove_default_cover')]);
    }
}
