<?php

namespace App\Http\Requests\Admin;

class CleanupSecurityLogsRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:4096'],
        ];
    }
}
