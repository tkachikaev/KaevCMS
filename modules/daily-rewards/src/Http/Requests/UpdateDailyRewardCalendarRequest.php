<?php

namespace KaevCMS\Modules\DailyRewards\Http\Requests;

use App\Auth\AdminPermission;
use App\Http\Requests\Admin\AdminFormRequest;
use App\Models\Admin;
use Illuminate\Validation\Validator;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardCalendar;

final class UpdateDailyRewardCalendarRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin instanceof Admin && $admin->hasPermission(AdminPermission::ModulesManage);
    }

    protected function prepareForValidation(): void
    {
        $days = $this->input('days');
        $normalizedDays = [];

        if (is_array($days)) {
            foreach ($days as $dayNumber => $day) {
                if (! is_array($day)) {
                    continue;
                }

                $rewards = [];
                foreach ((array) ($day['rewards'] ?? []) as $reward) {
                    if (! is_array($reward)) {
                        continue;
                    }

                    $itemId = $this->normalizedIntegerInput($reward['item_id'] ?? '');
                    $amount = $this->normalizedIntegerInput($reward['amount'] ?? '');
                    if ($itemId === '' && $amount === '') {
                        continue;
                    }

                    $rewards[] = ['item_id' => $itemId, 'amount' => $amount];
                }

                $normalizedDays[(string) $dayNumber] = [
                    'enabled' => filter_var($day['enabled'] ?? false, FILTER_VALIDATE_BOOL),
                    'rewards' => $rewards,
                ];
            }
        }

        $this->merge([
            'enabled' => $this->boolean('enabled'),
            'days' => $normalizedDays,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'days' => ['required', 'array', 'min:28', 'max:31'],
            'days.*.enabled' => ['required', 'boolean'],
            'days.*.rewards' => ['present', 'array', 'max:100'],
            'days.*.rewards.*.item_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
            'days.*.rewards.*.amount' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $calendar = $this->route('calendar');
            if (! $calendar instanceof DailyRewardCalendar) {
                return;
            }

            $days = (array) $this->input('days', []);
            $expected = range(1, $calendar->daysInMonth());
            $actual = array_map('intval', array_keys($days));
            sort($actual);
            if ($actual !== $expected) {
                $validator->errors()->add('days', __('module-daily-rewards::messages.validation_days_complete'));
            }

            $hasEnabledRewardDay = false;

            foreach ($days as $dayNumber => $day) {
                if (! is_array($day)) {
                    continue;
                }

                $rewards = (array) ($day['rewards'] ?? []);
                if ((bool) ($day['enabled'] ?? false) && $rewards === []) {
                    $validator->errors()->add(
                        'days.'.$dayNumber.'.rewards',
                        __('module-daily-rewards::messages.validation_enabled_day_rewards'),
                    );
                } elseif ((bool) ($day['enabled'] ?? false)) {
                    $hasEnabledRewardDay = true;
                }

                $seen = [];
                foreach ($rewards as $index => $reward) {
                    if (! is_array($reward)) {
                        continue;
                    }

                    $itemId = (int) ($reward['item_id'] ?? 0);
                    if ($itemId > 0 && isset($seen[$itemId])) {
                        $validator->errors()->add(
                            'days.'.$dayNumber.'.rewards.'.$index.'.item_id',
                            __('module-daily-rewards::messages.validation_reward_distinct'),
                        );
                    }
                    $seen[$itemId] = true;
                }
            }

            if ((bool) $this->input('enabled') && ! $hasEnabledRewardDay) {
                $validator->errors()->add(
                    'enabled',
                    __('module-daily-rewards::messages.enable_requires_rewards'),
                );
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'enabled' => __('module-daily-rewards::messages.state'),
            'days' => __('module-daily-rewards::messages.calendar_days'),
            'days.*.enabled' => __('module-daily-rewards::messages.day_enabled'),
            'days.*.rewards' => __('module-daily-rewards::messages.rewards'),
            'days.*.rewards.*.item_id' => __('module-daily-rewards::messages.item_id'),
            'days.*.rewards.*.amount' => __('module-daily-rewards::messages.amount'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => __('module-daily-rewards::messages.validation_required'),
            'present' => __('module-daily-rewards::messages.validation_required'),
            'array' => __('module-daily-rewards::messages.validation_array'),
            'integer' => __('module-daily-rewards::messages.validation_integer'),
            'boolean' => __('module-daily-rewards::messages.validation_boolean'),
            'min' => __('module-daily-rewards::messages.validation_min'),
            'max' => __('module-daily-rewards::messages.validation_max'),
        ];
    }

    private function normalizedIntegerInput(mixed $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/\A[0-9]+\z/', $value) !== 1) {
            return $value;
        }

        $normalized = ltrim($value, '0');

        return $normalized !== '' ? $normalized : '0';
    }
}
