<?php

namespace KaevCMS\Modules\SupportTickets\Services;

use App\Auth\AdminRole;
use App\Models\Admin;
use Illuminate\Support\Facades\Cache;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketStatus;
use KaevCMS\Modules\SupportTickets\Models\SupportTicket;
use Throwable;

final class SupportTicketAttentionCounter
{
    private const CACHE_KEY = 'module-support-tickets:attention-count';

    public function __construct(private readonly SupportTicketSettings $settings) {}

    public function countFor(Admin $admin): int
    {
        if (! $this->canRespond($admin)) {
            return 0;
        }

        try {
            return (int) Cache::remember(self::CACHE_KEY, now()->addSeconds(60), static fn (): int => SupportTicket::query()
                ->whereIn('status', [
                    SupportTicketStatus::New->value,
                    SupportTicketStatus::InProgress->value,
                ])
                ->count());
        } catch (Throwable) {
            return 0;
        }
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function canRespond(Admin $admin): bool
    {
        return in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true)
            || ($admin->role === AdminRole::Editor && $this->settings->editorCanReply());
    }
}
