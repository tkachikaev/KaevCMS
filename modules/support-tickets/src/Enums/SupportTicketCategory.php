<?php

namespace KaevCMS\Modules\SupportTickets\Enums;

enum SupportTicketCategory: string
{
    case Gameplay = 'gameplay';
    case GameAccount = 'game_account';
    case TechnicalProblem = 'technical_problem';
    case WebsiteError = 'website_error';
    case DonationsAndBonuses = 'donations_and_bonuses';
    case Complaint = 'complaint';
    case Other = 'other';

    public function label(): string
    {
        return __('module-support-tickets::messages.categories.'.$this->value);
    }
}
