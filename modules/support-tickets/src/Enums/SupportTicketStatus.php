<?php

namespace KaevCMS\Modules\SupportTickets\Enums;

enum SupportTicketStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case AwaitingPlayer = 'awaiting_player';
    case Closed = 'closed';

    public function label(): string
    {
        return __('module-support-tickets::messages.statuses.'.$this->value);
    }

    public function adminLabel(): string
    {
        return __('module-support-tickets::messages.admin_statuses.'.$this->value);
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::New => 'new',
            self::InProgress => 'in-progress',
            self::AwaitingPlayer => 'awaiting-player',
            self::Closed => 'closed',
        };
    }
}
