<?php

namespace App\Http\Requests\Admin;

use App\Services\MailSettings;
use Illuminate\Validation\Rule;

class ProbeMailDeliveryModeRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'delivery_mode' => ['required', Rule::in([
                MailSettings::MODE_BACKGROUND,
                MailSettings::MODE_DATABASE,
            ])],
        ];
    }
}
