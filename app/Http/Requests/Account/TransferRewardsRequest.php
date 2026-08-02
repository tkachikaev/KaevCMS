<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class TransferRewardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'game_server_id' => ['required', 'integer', 'min:1'],
            'character_id' => ['required', 'integer', 'min:1'],
            'inventory_item_ids' => ['required', 'array', 'min:1', 'max:50'],
            'inventory_item_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'request_token' => ['required', 'uuid'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'game_server_id.required' => __('The selected game server is unavailable. Refresh the page and try again.'),
            'game_server_id.integer' => __('The selected game server is unavailable. Refresh the page and try again.'),
            'game_server_id.min' => __('The selected game server is unavailable. Refresh the page and try again.'),
            'character_id.required' => __('Select a character.'),
            'character_id.integer' => __('Select a character.'),
            'character_id.min' => __('Select a character.'),
            'inventory_item_ids.required' => __('Select at least one reward.'),
            'inventory_item_ids.array' => __('Select at least one reward.'),
            'inventory_item_ids.min' => __('Select at least one reward.'),
            'inventory_item_ids.max' => __('Select no more than 50 rewards.'),
            'inventory_item_ids.*.required' => __('The selected rewards are invalid. Refresh the page and try again.'),
            'inventory_item_ids.*.integer' => __('The selected rewards are invalid. Refresh the page and try again.'),
            'inventory_item_ids.*.min' => __('The selected rewards are invalid. Refresh the page and try again.'),
            'inventory_item_ids.*.distinct' => __('The selected rewards are invalid. Refresh the page and try again.'),
            'request_token.required' => __('The transfer form has expired. Refresh the page and try again.'),
            'request_token.uuid' => __('The transfer form has expired. Refresh the page and try again.'),
        ];
    }
}
