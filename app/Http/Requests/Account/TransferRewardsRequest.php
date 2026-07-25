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
}
