<?php

namespace KaevCMS\Modules\SupportTickets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketCategory;
use KaevCMS\Modules\SupportTickets\Models\SupportTicket;

final class CreateSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'subject' => trim((string) $this->input('subject')),
            'body' => trim((string) $this->input('body')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(SupportTicketCategory::class)],
            'subject' => ['required', 'string', 'min:3', 'max:'.SupportTicket::SUBJECT_MAX],
            'body' => ['required', 'string', 'min:3', 'max:'.SupportTicket::INITIAL_MESSAGE_MAX],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'category' => __('module-support-tickets::messages.category'),
            'subject' => __('module-support-tickets::messages.subject'),
            'body' => __('module-support-tickets::messages.message'),
        ];
    }
}
