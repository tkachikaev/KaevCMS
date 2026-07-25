<?php

namespace App\Services\Mail;

use App\Models\MailDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class MailDeliveryMonitor
{
    public function start(?int $userId, string $type, string $recipient, string $mode): ?int
    {
        if (! $this->available()) {
            return null;
        }

        try {
            return (int) MailDelivery::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'recipient' => $recipient,
                'mode' => $mode,
                'status' => MailDelivery::STATUS_PENDING,
                'queued_at' => now(),
            ])->getKey();
        } catch (Throwable) {
            return null;
        }
    }

    public function markSent(?int $deliveryId): void
    {
        $this->update($deliveryId, [
            'status' => MailDelivery::STATUS_SENT,
            'sent_at' => now(),
            'failed_at' => null,
            'error_class' => null,
        ]);
    }

    public function markFailed(?int $deliveryId, Throwable|string $error): void
    {
        $this->update($deliveryId, [
            'status' => MailDelivery::STATUS_FAILED,
            'failed_at' => now(),
            'error_class' => is_string($error) ? $error : $error::class,
        ]);
    }

    public function markSkipped(?int $deliveryId, string $reason): void
    {
        $this->update($deliveryId, [
            'status' => MailDelivery::STATUS_SKIPPED,
            'failed_at' => null,
            'error_class' => $reason,
        ]);
    }

    /**
     * @return array{
     *     available: bool,
     *     pending: int,
     *     failed_recent: int,
     *     oldest_pending_at: Carbon|null,
     *     last_sent_at: Carbon|null,
     *     last_failed_at: Carbon|null,
     *     stale: bool
     * }
     */
    public function overview(): array
    {
        $empty = [
            'available' => false,
            'pending' => 0,
            'failed_recent' => 0,
            'oldest_pending_at' => null,
            'last_sent_at' => null,
            'last_failed_at' => null,
            'stale' => false,
        ];

        if (! $this->available()) {
            return $empty;
        }

        try {
            $oldestPending = MailDelivery::query()
                ->where('status', MailDelivery::STATUS_PENDING)
                ->min('queued_at');

            $oldestPendingAt = is_string($oldestPending) ? Carbon::parse($oldestPending) : null;

            return [
                'available' => true,
                'pending' => MailDelivery::query()->where('status', MailDelivery::STATUS_PENDING)->count(),
                'failed_recent' => MailDelivery::query()
                    ->where('status', MailDelivery::STATUS_FAILED)
                    ->where('failed_at', '>=', now()->subDays(7))
                    ->count(),
                'oldest_pending_at' => $oldestPendingAt,
                'last_sent_at' => $this->latestTimestamp('sent_at', MailDelivery::STATUS_SENT),
                'last_failed_at' => $this->latestTimestamp('failed_at', MailDelivery::STATUS_FAILED),
                'stale' => $oldestPendingAt !== null && $oldestPendingAt->lt(now()->subMinutes(2)),
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /** @param array<string, mixed> $values */
    private function update(?int $deliveryId, array $values): void
    {
        if ($deliveryId === null || ! $this->available()) {
            return;
        }

        try {
            MailDelivery::query()->whereKey($deliveryId)->update($values);
        } catch (Throwable) {
            // Mail delivery must not fail only because monitoring data could not be updated.
        }
    }

    private function latestTimestamp(string $column, string $status): ?Carbon
    {
        $value = MailDelivery::query()->where('status', $status)->max($column);

        return is_string($value) ? Carbon::parse($value) : null;
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable('mail_deliveries');
        } catch (Throwable) {
            return false;
        }
    }
}
