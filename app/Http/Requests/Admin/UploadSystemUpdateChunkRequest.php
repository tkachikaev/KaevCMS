<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UploadSystemUpdateChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->isOwner() === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'upload_id' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:100000'],
            'size' => ['required', 'integer', 'min:1'],
            'chunk' => ['required', 'file'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'chunk.uploaded' => __('An upload part is larger than this server accepts. Start the upload again.'),
        ];
    }
}
