<?php

namespace KaevCMS\Modules\SupportTickets\Http\Requests;

use App\Http\Requests\Admin\AdminFormRequest;
use KaevCMS\Modules\SupportTickets\Models\SupportTicket;

final class AdminSupportTicketMessageRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['body' => trim((string) $this->input('body'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'min:1', 'max:'.SupportTicket::MESSAGE_MAX]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['body' => __('module-support-tickets::messages.message')];
    }
}
