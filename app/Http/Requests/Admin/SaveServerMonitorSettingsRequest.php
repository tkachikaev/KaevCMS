<?php

namespace App\Http\Requests\Admin;

use App\Services\Servers\ServerMonitorSettings;
use Illuminate\Validation\Rule;

class SaveServerMonitorSettingsRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'refresh_interval_seconds' => [
                'required',
                'integer',
                Rule::in(ServerMonitorSettings::REFRESH_INTERVAL_OPTIONS),
            ],
        ];
    }
}
