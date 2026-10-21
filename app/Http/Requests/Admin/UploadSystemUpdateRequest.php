<?php

namespace App\Http\Requests\Admin;

use App\Services\Updates\UpdateUploadLimit;
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

    /** @return array<string, string> */
    public function messages(): array
    {
        // PHP drops a file above upload_max_filesize before Laravel sees it.
        $tooLarge = __('The update package is larger than this server accepts through the browser (:limit). Raise the PHP upload limit or place the ZIP in the upload folder through FTP or the file manager.', [
            'limit' => app(UpdateUploadLimit::class)->label(),
        ]);

        return [
            'package.uploaded' => $tooLarge,
            'package.max' => $tooLarge,
        ];
    }
}
