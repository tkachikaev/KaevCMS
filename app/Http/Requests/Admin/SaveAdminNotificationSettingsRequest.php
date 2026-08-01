<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

final class SaveAdminNotificationSettingsRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'notification_support' => ['nullable', 'boolean'],
            'notification_modules' => ['nullable', 'boolean'],
            'notification_cms_updates' => ['nullable', 'boolean'],
            'notification_background_tasks' => ['nullable', 'boolean'],
            'notification_login_server' => ['nullable', 'boolean'],
            'notification_game_server' => ['nullable', 'boolean'],
            'notification_disk_space' => ['nullable', 'boolean'],
            'notification_installer' => ['nullable', 'boolean'],
            'notification_diagnostics' => ['nullable', 'boolean'],
            'notification_auto_cleanup' => ['nullable', 'boolean'],
            'notification_retention_days' => ['required', 'integer', Rule::in([30, 60, 90, 180])],
        ];
    }
}
