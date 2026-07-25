<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UploadSystemUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->isOwner() === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $maximumKilobytes = max(1024, (int) config('cms.updates.maximum_upload_kilobytes', 524288));

        return [
            'package' => ['required', 'file', "max:{$maximumKilobytes}"],
        ];
    }
}
