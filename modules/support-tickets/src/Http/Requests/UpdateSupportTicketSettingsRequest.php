<?php

namespace KaevCMS\Modules\SupportTickets\Http\Requests;

use App\Http\Requests\Admin\AdminFormRequest;
use Illuminate\Validation\Rule;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings;

final class UpdateSupportTicketSettingsRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        $current = app(SupportTicketSettings::class)->values();

        $this->merge([
            'allow_editor_view' => $this->boolean('allow_editor_view'),
            'allow_editor_reply' => $this->boolean('allow_editor_reply'),
            'allow_editor_internal_notes' => $this->boolean('allow_editor_internal_notes'),
            'automatic_cleanup_enabled' => $this->boolean('automatic_cleanup_enabled'),
            'max_tickets_per_day' => $this->input('max_tickets_per_day', $current['max_tickets_per_day']),
            'max_player_messages_per_day' => $this->input(
                'max_player_messages_per_day',
                $current['max_player_messages_per_day'],
            ),
            'max_messages_per_ticket' => $this->input(
                'max_messages_per_ticket',
                $current['max_messages_per_ticket'],
            ),
            'max_revisions_per_message' => $this->input(
                'max_revisions_per_message',
                $current['max_revisions_per_message'],
            ),
            'max_open_tickets_per_user' => $this->input(
                'max_open_tickets_per_user',
                $current['max_open_tickets_per_user'],
            ),
            'subject_max_length' => $this->input('subject_max_length', $current['subject_max_length']),
            'initial_message_max_length' => $this->input(
                'initial_message_max_length',
                $current['initial_message_max_length'],
            ),
            'message_max_length' => $this->input('message_max_length', $current['message_max_length']),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'allow_editor_view' => ['required', 'boolean'],
            'allow_editor_reply' => ['required', 'boolean'],
            'allow_editor_internal_notes' => ['required', 'boolean'],
            'retention_months' => ['required', 'integer', Rule::in([0, 6, 12, 24, 36])],
            'automatic_cleanup_enabled' => ['required', 'boolean'],
            'max_tickets_per_day' => [
                'required',
                'integer',
                'min:'.SupportTicketSettings::MIN_MAX_TICKETS_PER_DAY,
                'max:'.SupportTicketSettings::MAX_MAX_TICKETS_PER_DAY,
            ],
            'max_player_messages_per_day' => [
                'required',
                'integer',
                'min:'.SupportTicketSettings::MIN_MAX_PLAYER_MESSAGES_PER_DAY,
                'max:'.SupportTicketSettings::MAX_MAX_PLAYER_MESSAGES_PER_DAY,
            ],
            'max_messages_per_ticket' => [
                'required',
                'integer',
                'min:'.SupportTicketSettings::MIN_MAX_MESSAGES_PER_TICKET,
                'max:'.SupportTicketSettings::MAX_MAX_MESSAGES_PER_TICKET,
            ],
            'max_revisions_per_message' => [
                'required',
                'integer',
                'min:'.SupportTicketSettings::MIN_MAX_REVISIONS_PER_MESSAGE,
                'max:'.SupportTicketSettings::MAX_MAX_REVISIONS_PER_MESSAGE,
            ],
            'max_open_tickets_per_user' => [
                'required',
                'integer',
                'min:'.SupportTicketSettings::MIN_MAX_OPEN_TICKETS_PER_USER,
                'max:'.SupportTicketSettings::MAX_MAX_OPEN_TICKETS_PER_USER,
            ],
            'subject_max_length' => [
                'required',
                'integer',
                'min:'.SupportTicketSettings::MIN_SUBJECT_MAX_LENGTH,
                'max:'.SupportTicketSettings::MAX_SUBJECT_MAX_LENGTH,
            ],
            'initial_message_max_length' => [
                'required',
                'integer',
                'min:'.SupportTicketSettings::MIN_INITIAL_MESSAGE_MAX_LENGTH,
                'max:'.SupportTicketSettings::MAX_INITIAL_MESSAGE_MAX_LENGTH,
            ],
            'message_max_length' => [
                'required',
                'integer',
                'min:'.SupportTicketSettings::MIN_MESSAGE_MAX_LENGTH,
                'max:'.SupportTicketSettings::MAX_MESSAGE_MAX_LENGTH,
            ],
        ];
    }
}
