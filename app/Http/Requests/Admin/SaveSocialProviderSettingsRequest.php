<?php

namespace App\Http\Requests\Admin;

class SaveSocialProviderSettingsRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'client_secret' => ['nullable', 'string', 'max:2048', 'not_regex:/[\r\n]/'],
        ];
    }
}
