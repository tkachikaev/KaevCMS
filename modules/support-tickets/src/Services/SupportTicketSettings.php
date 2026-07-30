<?php

namespace KaevCMS\Modules\SupportTickets\Services;

use App\Models\Admin;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\SupportTickets\Models\SupportTicketSetting;
use Throwable;

final class SupportTicketSettings
{
    public const DEFAULT_RETENTION_MONTHS = 24;

    public const DEFAULT_MAX_TICKETS_PER_DAY = 10;

    public const DEFAULT_MAX_PLAYER_MESSAGES_PER_DAY = 100;

    public const DEFAULT_MAX_MESSAGES_PER_TICKET = 300;

    public const DEFAULT_MAX_REVISIONS_PER_MESSAGE = 20;

    public const DEFAULT_MAX_OPEN_TICKETS_PER_USER = 5;

    public const DEFAULT_SUBJECT_MAX_LENGTH = 120;

    public const DEFAULT_INITIAL_MESSAGE_MAX_LENGTH = 3000;

    public const DEFAULT_MESSAGE_MAX_LENGTH = 2000;

    public const MIN_MAX_TICKETS_PER_DAY = 1;

    public const MAX_MAX_TICKETS_PER_DAY = 50;

    public const MIN_MAX_PLAYER_MESSAGES_PER_DAY = 10;

    public const MAX_MAX_PLAYER_MESSAGES_PER_DAY = 1000;

    public const MIN_MAX_MESSAGES_PER_TICKET = 20;

    public const MAX_MAX_MESSAGES_PER_TICKET = 2000;

    public const MIN_MAX_REVISIONS_PER_MESSAGE = 1;

    public const MAX_MAX_REVISIONS_PER_MESSAGE = 100;

    public const MIN_MAX_OPEN_TICKETS_PER_USER = 1;

    public const MAX_MAX_OPEN_TICKETS_PER_USER = 50;

    public const MIN_SUBJECT_MAX_LENGTH = 30;

    public const MAX_SUBJECT_MAX_LENGTH = 120;

    public const MIN_INITIAL_MESSAGE_MAX_LENGTH = 300;

    public const MAX_INITIAL_MESSAGE_MAX_LENGTH = 10000;

    public const MIN_MESSAGE_MAX_LENGTH = 100;

    public const MAX_MESSAGE_MAX_LENGTH = 10000;

    /** @return array<string, bool|int> */
    public function values(): array
    {
        try {
            if (Schema::hasTable('module_support_ticket_settings') === false) {
                return $this->defaults();
            }

            $settings = SupportTicketSetting::query()->find(1);
            if (($settings instanceof SupportTicketSetting) === false) {
                return $this->defaults();
            }

            return [
                'allow_editor_view' => $settings->allow_editor_view,
                'allow_editor_reply' => $settings->allow_editor_reply,
                'allow_editor_internal_notes' => $settings->allow_editor_internal_notes,
                'retention_months' => $this->boundedInt(
                    $settings->retention_months,
                    self::DEFAULT_RETENTION_MONTHS,
                    0,
                    36,
                ),
                'automatic_cleanup_enabled' => $settings->automatic_cleanup_enabled,
                'max_tickets_per_day' => $this->boundedInt(
                    $settings->max_tickets_per_day,
                    self::DEFAULT_MAX_TICKETS_PER_DAY,
                    self::MIN_MAX_TICKETS_PER_DAY,
                    self::MAX_MAX_TICKETS_PER_DAY,
                ),
                'max_player_messages_per_day' => $this->boundedInt(
                    $settings->max_player_messages_per_day,
                    self::DEFAULT_MAX_PLAYER_MESSAGES_PER_DAY,
                    self::MIN_MAX_PLAYER_MESSAGES_PER_DAY,
                    self::MAX_MAX_PLAYER_MESSAGES_PER_DAY,
                ),
                'max_messages_per_ticket' => $this->boundedInt(
                    $settings->max_messages_per_ticket,
                    self::DEFAULT_MAX_MESSAGES_PER_TICKET,
                    self::MIN_MAX_MESSAGES_PER_TICKET,
                    self::MAX_MAX_MESSAGES_PER_TICKET,
                ),
                'max_revisions_per_message' => $this->boundedInt(
                    $settings->max_revisions_per_message,
                    self::DEFAULT_MAX_REVISIONS_PER_MESSAGE,
                    self::MIN_MAX_REVISIONS_PER_MESSAGE,
                    self::MAX_MAX_REVISIONS_PER_MESSAGE,
                ),
                'max_open_tickets_per_user' => $this->boundedInt(
                    $settings->max_open_tickets_per_user,
                    self::DEFAULT_MAX_OPEN_TICKETS_PER_USER,
                    self::MIN_MAX_OPEN_TICKETS_PER_USER,
                    self::MAX_MAX_OPEN_TICKETS_PER_USER,
                ),
                'subject_max_length' => $this->boundedInt(
                    $settings->subject_max_length,
                    self::DEFAULT_SUBJECT_MAX_LENGTH,
                    self::MIN_SUBJECT_MAX_LENGTH,
                    self::MAX_SUBJECT_MAX_LENGTH,
                ),
                'initial_message_max_length' => $this->boundedInt(
                    $settings->initial_message_max_length,
                    self::DEFAULT_INITIAL_MESSAGE_MAX_LENGTH,
                    self::MIN_INITIAL_MESSAGE_MAX_LENGTH,
                    self::MAX_INITIAL_MESSAGE_MAX_LENGTH,
                ),
                'message_max_length' => $this->boundedInt(
                    $settings->message_max_length,
                    self::DEFAULT_MESSAGE_MAX_LENGTH,
                    self::MIN_MESSAGE_MAX_LENGTH,
                    self::MAX_MESSAGE_MAX_LENGTH,
                ),
            ];
        } catch (Throwable) {
            return $this->defaults();
        }
    }

    public function editorCanView(): bool
    {
        return (bool) $this->values()['allow_editor_view'];
    }

    public function editorCanReply(): bool
    {
        $settings = $this->values();

        return (bool) $settings['allow_editor_view'] && (bool) $settings['allow_editor_reply'];
    }

    public function editorCanAddInternalNotes(): bool
    {
        $settings = $this->values();

        return (bool) $settings['allow_editor_view'] && (bool) $settings['allow_editor_internal_notes'];
    }

    public function retentionMonths(): int
    {
        return (int) $this->values()['retention_months'];
    }

    public function automaticCleanupEnabled(): bool
    {
        return (bool) $this->values()['automatic_cleanup_enabled'];
    }

    public function maxTicketsPerDay(): int
    {
        return (int) $this->values()['max_tickets_per_day'];
    }

    public function maxPlayerMessagesPerDay(): int
    {
        return (int) $this->values()['max_player_messages_per_day'];
    }

    public function maxMessagesPerTicket(): int
    {
        return (int) $this->values()['max_messages_per_ticket'];
    }

    public function maxRevisionsPerMessage(): int
    {
        return (int) $this->values()['max_revisions_per_message'];
    }

    public function maxOpenTicketsPerUser(): int
    {
        return (int) $this->values()['max_open_tickets_per_user'];
    }

    public function subjectMaxLength(): int
    {
        return (int) $this->values()['subject_max_length'];
    }

    public function initialMessageMaxLength(): int
    {
        return (int) $this->values()['initial_message_max_length'];
    }

    public function messageMaxLength(): int
    {
        return (int) $this->values()['message_max_length'];
    }

    /** @param array<string, bool|int> $values */
    public function update(array $values, Admin $admin): SupportTicketSetting
    {
        return SupportTicketSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'allow_editor_management' => false,
                'allow_editor_view' => $values['allow_editor_view'],
                'allow_editor_reply' => $values['allow_editor_reply'],
                'allow_editor_internal_notes' => $values['allow_editor_internal_notes'],
                'retention_months' => $values['retention_months'],
                'automatic_cleanup_enabled' => $values['automatic_cleanup_enabled'],
                'max_tickets_per_day' => $values['max_tickets_per_day'],
                'max_player_messages_per_day' => $values['max_player_messages_per_day'],
                'max_messages_per_ticket' => $values['max_messages_per_ticket'],
                'max_revisions_per_message' => $values['max_revisions_per_message'],
                'max_open_tickets_per_user' => $values['max_open_tickets_per_user'],
                'subject_max_length' => $values['subject_max_length'],
                'initial_message_max_length' => $values['initial_message_max_length'],
                'message_max_length' => $values['message_max_length'],
                'updated_by_admin_id' => $admin->id,
            ],
        );
    }

    /** @return array<string, bool|int> */
    private function defaults(): array
    {
        return [
            'allow_editor_view' => false,
            'allow_editor_reply' => false,
            'allow_editor_internal_notes' => false,
            'retention_months' => self::DEFAULT_RETENTION_MONTHS,
            'automatic_cleanup_enabled' => true,
            'max_tickets_per_day' => self::DEFAULT_MAX_TICKETS_PER_DAY,
            'max_player_messages_per_day' => self::DEFAULT_MAX_PLAYER_MESSAGES_PER_DAY,
            'max_messages_per_ticket' => self::DEFAULT_MAX_MESSAGES_PER_TICKET,
            'max_revisions_per_message' => self::DEFAULT_MAX_REVISIONS_PER_MESSAGE,
            'max_open_tickets_per_user' => self::DEFAULT_MAX_OPEN_TICKETS_PER_USER,
            'subject_max_length' => self::DEFAULT_SUBJECT_MAX_LENGTH,
            'initial_message_max_length' => self::DEFAULT_INITIAL_MESSAGE_MAX_LENGTH,
            'message_max_length' => self::DEFAULT_MESSAGE_MAX_LENGTH,
        ];
    }

    private function boundedInt(mixed $value, int $default, int $minimum, int $maximum): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $number = (int) $value;

            return $number >= $minimum && $number <= $maximum ? $number : $default;
        }

        return $default;
    }
}
