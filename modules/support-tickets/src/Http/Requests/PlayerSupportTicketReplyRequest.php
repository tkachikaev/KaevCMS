<?php

namespace KaevCMS\Modules\SupportTickets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings;

final class PlayerSupportTicketReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['body' => trim((string) $this->input('body'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['body' => [
            'required',
            'string',
            'min:1',
            'max:'.app(SupportTicketSettings::class)->messageMaxLength(),
        ]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['body' => __('module-support-tickets::messages.message')];
    }
}
