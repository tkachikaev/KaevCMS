<?php

namespace KaevCMS\Modules\PromoCodes\Http\Requests;

use App\Http\Requests\Admin\AdminFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use KaevCMS\Modules\PromoCodes\Models\PromoCode;

final class SavePromoCodeRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        $rewards = $this->input('rewards');
        if (is_array($rewards)) {
            $normalized = [];

            foreach ($rewards as $reward) {
                if (! is_array($reward)) {
                    continue;
                }

                $itemId = $this->normalizedIntegerInput($reward['item_id'] ?? '');
                $amount = $this->normalizedIntegerInput($reward['amount'] ?? '');

                if ($itemId === '' && $amount === '') {
                    continue;
                }

                $normalized[] = [
                    'item_id' => $itemId,
                    'amount' => $amount,
                ];
            }

            $rewards = $normalized;
        }

        $this->merge([
            'code' => PromoCode::normalizeCode((string) $this->input('code')),
            'starts_at' => $this->nullableInput('starts_at'),
            'ends_at' => $this->nullableInput('ends_at'),
            'enabled' => $this->boolean('enabled'),
            'rewards' => $rewards,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $promoCode = $this->route('promoCode');
        $promoCodeId = $promoCode instanceof PromoCode ? $promoCode->id : null;

        return [
            'code' => [
                'required',
                'string',
                'min:4',
                'max:64',
                'regex:/\A[A-Z0-9][A-Z0-9_-]{3,63}\z/',
                Rule::unique('module_promo_codes', 'code')->ignore($promoCodeId),
            ],
            'game_server_id' => ['required', 'integer', 'exists:game_servers,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', Rule::when($this->filled('starts_at'), 'after:starts_at')],
            'total_limit' => ['required', 'integer', 'min:0', 'max:9223372036854775807'],
            'per_user_limit' => ['required', 'integer', 'min:1', 'max:1000000'],
            'enabled' => ['required', 'boolean'],
            'rewards' => ['required', 'array', 'min:1', 'max:100'],
            'rewards.*.item_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807', 'distinct:strict'],
            'rewards.*.amount' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $promoCode = $this->route('promoCode');
            if (! $promoCode instanceof PromoCode) {
                return;
            }

            $totalLimit = filter_var($this->input('total_limit'), FILTER_VALIDATE_INT);
            if (is_int($totalLimit) && $totalLimit > 0 && $totalLimit < $promoCode->activations_count) {
                $validator->errors()->add(
                    'total_limit',
                    __('module-promo-codes::messages.total_limit_below_activations', [
                        'count' => $promoCode->activations_count,
                    ]),
                );
            }

            $gameServerId = filter_var($this->input('game_server_id'), FILTER_VALIDATE_INT);
            if (
                is_int($gameServerId)
                && $promoCode->activations_count > 0
                && $gameServerId !== $promoCode->game_server_id
            ) {
                $validator->errors()->add(
                    'game_server_id',
                    __('module-promo-codes::messages.server_locked_after_activation'),
                );
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => __('module-promo-codes::messages.attribute_code'),
            'game_server_id' => __('module-promo-codes::messages.attribute_game_server'),
            'starts_at' => __('module-promo-codes::messages.attribute_starts_at'),
            'ends_at' => __('module-promo-codes::messages.attribute_ends_at'),
            'total_limit' => __('module-promo-codes::messages.attribute_total_limit'),
            'per_user_limit' => __('module-promo-codes::messages.attribute_per_user_limit'),
            'enabled' => __('module-promo-codes::messages.attribute_enabled'),
            'rewards' => __('module-promo-codes::messages.attribute_rewards'),
            'rewards.*.item_id' => __('module-promo-codes::messages.attribute_item_id'),
            'rewards.*.amount' => __('module-promo-codes::messages.attribute_amount'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => __('module-promo-codes::messages.validation_required'),
            'integer' => __('module-promo-codes::messages.validation_integer'),
            'boolean' => __('module-promo-codes::messages.validation_boolean'),
            'date' => __('module-promo-codes::messages.validation_date'),
            'after' => __('module-promo-codes::messages.validation_end_after_start'),
            'min' => __('module-promo-codes::messages.validation_min'),
            'max' => __('module-promo-codes::messages.validation_max'),
            'regex' => __('module-promo-codes::messages.validation_code_format'),
            'unique' => __('module-promo-codes::messages.validation_code_unique'),
            'exists' => __('module-promo-codes::messages.validation_server_exists'),
            'distinct' => __('module-promo-codes::messages.validation_reward_distinct'),
        ];
    }

    private function nullableInput(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value !== '' ? $value : null;
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
