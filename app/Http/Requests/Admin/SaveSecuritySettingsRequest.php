<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Validator;

class SaveSecuritySettingsRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'login_ip_per_minute' => ['required', 'integer', 'between:5,60'],
            'login_ip_per_hour' => ['required', 'integer', 'between:30,1000'],
            'login_max_attempts' => ['required', 'integer', 'between:3,20'],
            'login_decay_minutes' => ['required', 'integer', 'between:1,60'],
            'audit_retention_days' => ['required', 'integer', 'between:30,730'],
            'admin_login_retention_days' => ['required', 'integer', 'between:7,365'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $perMinute = $this->integer('login_ip_per_minute');
            $perHour = $this->integer('login_ip_per_hour');

            if ($perHour < $perMinute) {
                $validator->errors()->add(
                    'login_ip_per_hour',
                    __('The hourly IP limit cannot be lower than the per-minute limit.'),
                );
            }
        });
    }
}
