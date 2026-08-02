<?php

namespace KaevCMS\Modules\SupportTickets\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketStatus;
use KaevCMS\Modules\SupportTickets\Models\SupportTicket;
use KaevCMS\Modules\SupportTickets\Models\SupportTicketMessage;
use KaevCMS\Modules\SupportTickets\Models\SupportTicketMessageRevision;

final class SupportTicketCleanupService
{
    /** @return array{retention_months: int, cutoff: string|null, tickets: int, messages: int, revisions: int, oldest_closed_at: string|null, newest_closed_at: string|null} */
    public function preview(int $retentionMonths): array
    {
        $cutoff = $this->cutoff($retentionMonths);
        if ($cutoff === null) {
            return $this->emptyResult($retentionMonths);
        }

        $query = $this->eligibleQuery($cutoff);
        $ticketIds = (clone $query)->select('id');
        $messageIds = SupportTicketMessage::query()
            ->whereIn('ticket_id', clone $ticketIds)
            ->select('id');

        $oldestClosedAt = (clone $query)->min('closed_at');
        $newestClosedAt = (clone $query)->max('closed_at');

        return [
            'retention_months' => $retentionMonths,
            'cutoff' => $cutoff,
            'tickets' => (clone $query)->count(),
            'messages' => SupportTicketMessage::query()->whereIn('ticket_id', clone $ticketIds)->count(),
            'revisions' => SupportTicketMessageRevision::query()->whereIn('message_id', $messageIds)->count(),
            'oldest_closed_at' => is_string($oldestClosedAt) ? $oldestClosedAt : null,
            'newest_closed_at' => is_string($newestClosedAt) ? $newestClosedAt : null,
        ];
    }

    /** @return array{ticket_id: int, messages: int, revisions: int} */
    public function deleteClosedTicket(SupportTicket $ticket): array
    {
        return DB::transaction(function () use ($ticket): array {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if (! $locked->isClosed()) {
                throw ValidationException::withMessages([
                    'ticket' => __('module-support-tickets::messages.delete_closed_only'),
                ]);
            }
            if ($locked->retention_protected) {
                throw ValidationException::withMessages([
                    'ticket' => __('module-support-tickets::messages.delete_protected_ticket'),
                ]);
            }

            $messageIds = SupportTicketMessage::query()
                ->where('ticket_id', $locked->id)
                ->pluck('id');
            $revisionCount = $messageIds->isEmpty()
                ? 0
                : SupportTicketMessageRevision::query()->whereIn('message_id', $messageIds->all())->count();
            $result = [
                'ticket_id' => $locked->id,
                'messages' => $messageIds->count(),
                'revisions' => $revisionCount,
            ];

            $locked->delete();

            return $result;
        }, 3);
    }

    /** @return array{retention_months: int, cutoff: string|null, tickets: int, messages: int, revisions: int, oldest_closed_at: string|null, newest_closed_at: string|null} */
    public function cleanup(int $retentionMonths, int $batchSize = 100): array
    {
        $cutoff = $this->cutoff($retentionMonths);
        if ($cutoff === null) {
            return $this->emptyResult($retentionMonths);
        }

        $batchSize = min(1000, max(1, $batchSize));
        $result = $this->emptyResult($retentionMonths, $cutoff);

        do {
            /** @var array{selected: int, tickets: int, messages: int, revisions: int, oldest_closed_at: string|null, newest_closed_at: string|null} $batch */
            $batch = DB::transaction(
                fn (): array => $this->deleteLockedBatch($cutoff, $batchSize),
                3,
            );

            $result['tickets'] += $batch['tickets'];
            $result['messages'] += $batch['messages'];
            $result['revisions'] += $batch['revisions'];
            $result['oldest_closed_at'] = $this->earliest(
                $result['oldest_closed_at'],
                $batch['oldest_closed_at'],
            );
            $result['newest_closed_at'] = $this->latest(
                $result['newest_closed_at'],
                $batch['newest_closed_at'],
            );
        } while ($batch['selected'] === $batchSize);

        return $result;
    }

    /**
     * @return array{selected: int, tickets: int, messages: int, revisions: int, oldest_closed_at: string|null, newest_closed_at: string|null}
     */
    private function deleteLockedBatch(string $cutoff, int $batchSize): array
    {
        /** @var Collection<int, SupportTicket> $tickets */
        $tickets = $this->eligibleQuery($cutoff)
            ->orderBy('id')
            ->limit($batchSize)
            ->lockForUpdate()
            ->get(['id', 'closed_at']);

        $result = [
            'selected' => $tickets->count(),
            'tickets' => 0,
            'messages' => 0,
            'revisions' => 0,
            'oldest_closed_at' => null,
            'newest_closed_at' => null,
        ];

        foreach ($tickets as $ticket) {
            $messageIds = SupportTicketMessage::query()
                ->where('ticket_id', $ticket->id)
                ->pluck('id');
            $messageCount = $messageIds->count();
            $revisionCount = $messageIds->isEmpty()
                ? 0
                : SupportTicketMessageRevision::query()->whereIn('message_id', $messageIds->all())->count();

            $deleted = $this->eligibleQuery($cutoff)
                ->whereKey($ticket->id)
                ->delete();
            if ($deleted !== 1) {
                continue;
            }

            $closedAt = $ticket->closed_at?->toDateTimeString();
            $result['tickets']++;
            $result['messages'] += $messageCount;
            $result['revisions'] += $revisionCount;
            $result['oldest_closed_at'] = $this->earliest($result['oldest_closed_at'], $closedAt);
            $result['newest_closed_at'] = $this->latest($result['newest_closed_at'], $closedAt);
        }

        return $result;
    }

    /** @return Builder<SupportTicket> */
    private function eligibleQuery(string $cutoff): Builder
    {
        return SupportTicket::query()
            ->where('status', SupportTicketStatus::Closed->value)
            ->where('retention_protected', false)
            ->whereNotNull('closed_at')
            ->where('closed_at', '<=', $cutoff);
    }

    private function cutoff(int $retentionMonths): ?string
    {
        return $retentionMonths === 0
            ? null
            : now()->subMonthsNoOverflow($retentionMonths)->toDateTimeString();
    }

    private function earliest(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null) {
            return $current;
        }

        return $current === null || $candidate < $current ? $candidate : $current;
    }

    private function latest(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null) {
            return $current;
        }

        return $current === null || $candidate > $current ? $candidate : $current;
    }

    /** @return array{retention_months: int, cutoff: string|null, tickets: int, messages: int, revisions: int, oldest_closed_at: string|null, newest_closed_at: string|null} */
    private function emptyResult(int $retentionMonths, ?string $cutoff = null): array
    {
        return [
            'retention_months' => $retentionMonths,
            'cutoff' => $cutoff,
            'tickets' => 0,
            'messages' => 0,
            'revisions' => 0,
            'oldest_closed_at' => null,
            'newest_closed_at' => null,
        ];
    }
}
