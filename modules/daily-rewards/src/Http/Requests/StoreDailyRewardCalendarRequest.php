<?php

namespace KaevCMS\Modules\DailyRewards\Http\Requests;

use App\Auth\AdminPermission;
use App\Http\Requests\Admin\AdminFormRequest;
use App\Models\Admin;
use Illuminate\Validation\Validator;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardCalendar;

final class StoreDailyRewardCalendarRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin instanceof Admin && $admin->hasPermission(AdminPermission::ModulesManage);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['enabled' => false]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'game_server_id' => ['required', 'integer', 'exists:game_servers,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $serverId = filter_var($this->input('game_server_id'), FILTER_VALIDATE_INT);
            $year = filter_var($this->input('year'), FILTER_VALIDATE_INT);
            $month = filter_var($this->input('month'), FILTER_VALIDATE_INT);
            if (! is_int($serverId) || ! is_int($year) || ! is_int($month)) {
                return;
            }

            if (DailyRewardCalendar::query()
                ->where('game_server_id', $serverId)
                ->where('year', $year)
                ->where('month', $month)
                ->exists()) {
                $validator->errors()->add(
                    'game_server_id',
                    __('module-daily-rewards::messages.validation_calendar_unique'),
                );
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'game_server_id' => __('module-daily-rewards::messages.game_server'),
            'year' => __('module-daily-rewards::messages.year'),
            'month' => __('module-daily-rewards::messages.month'),
            'enabled' => __('module-daily-rewards::messages.state'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => __('module-daily-rewards::messages.validation_required'),
            'integer' => __('module-daily-rewards::messages.validation_integer'),
            'boolean' => __('module-daily-rewards::messages.validation_boolean'),
            'exists' => __('module-daily-rewards::messages.validation_server_exists'),
            'unique' => __('module-daily-rewards::messages.validation_calendar_unique'),
            'min' => __('module-daily-rewards::messages.validation_min'),
            'max' => __('module-daily-rewards::messages.validation_max'),
        ];
    }
}
