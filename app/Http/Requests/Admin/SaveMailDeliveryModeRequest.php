<?php

namespace App\Http\Requests\Admin;

use App\Services\MailSettings;
use Illuminate\Validation\Rule;

class SaveMailDeliveryModeRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'delivery_mode' => ['required', Rule::in([
                MailSettings::MODE_SYNC,
                MailSettings::MODE_BACKGROUND,
                MailSettings::MODE_DATABASE,
            ])],
        ];
    }
}
