<?php

namespace App\Http\Requests\Admin;

class ToggleSocialProviderRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean']];
    }
}
