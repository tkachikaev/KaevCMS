<?php

namespace KaevCMS\Modules\DailyRewards\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ClaimDailyRewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'request_token' => trim((string) $this->input('request_token')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'calendar_id' => ['required', 'integer', 'exists:module_daily_reward_calendars,id'],
            'user_game_account_id' => ['required', 'integer', 'exists:user_game_accounts,id'],
            'request_token' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'calendar_id' => __('module-daily-rewards::messages.calendar'),
            'user_game_account_id' => __('module-daily-rewards::messages.game_account'),
            'request_token' => __('module-daily-rewards::messages.request_token'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => __('module-daily-rewards::messages.validation_required'),
            'integer' => __('module-daily-rewards::messages.validation_integer'),
            'exists' => __('module-daily-rewards::messages.validation_selection_exists'),
            'uuid' => __('module-daily-rewards::messages.validation_request_token'),
        ];
    }
}
