<?php

namespace App\Http\Requests\Admin;

/** Status changes are never inferred from the current visible page state. */
final class ChangeWalletStatusRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:block,unblock'],
            'reason' => ['required', 'string', 'min:3', 'max:190'],
            'operation_key' => ['required', 'uuid'],
            'confirm_status_change' => ['required', 'accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
