<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ImportSystemUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->isOwner() === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'incoming' => ['required', 'string', 'max:255'],
        ];
    }
}
