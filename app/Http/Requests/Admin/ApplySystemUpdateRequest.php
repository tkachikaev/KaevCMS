<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ApplySystemUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->isOwner() === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:admin'],
            'confirmation' => ['accepted'],
        ];
    }
}
