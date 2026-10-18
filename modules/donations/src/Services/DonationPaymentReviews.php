<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use LogicException;
use RuntimeException;

/**
 * Append-only HUMAN REPORTED refund/chargeback/dispute evidence.
 * This does not certify a provider refund, change orders, or touch Wallet.
 */
final class DonationPaymentReviews
{
    public const KINDS = ['dispute_reported', 'refund_reported', 'chargeback_reported', 'review_note', 'review_resolved'];

    public function __construct(private readonly AuditLogger $audit) {}

    public static function canReport(Admin $admin): bool
    {
        return $admin->exists && $admin->is_active
            && in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true);
    }

    public function record(Admin $admin, string $orderUuid, int $receiptId, string $kind, string $reason, string $requestUuid): int
    {
        if (! self::canReport($admin)) {
            throw new AuthorizationException('Not authorized to record payment review evidence.');
        }
        if (! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $orderUuid)
            || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $requestUuid)
            || ! in_array($kind, self::KINDS, true) || $receiptId < 1
            || strlen($reason) < 10 || strlen($reason) > 1000 || trim($reason) !== $reason) {
            throw new InvalidArgumentException('Invalid payment review report.');
        }
        // Never include arbitrary payment card data, API credentials or tokens in annotations.
        if (preg_match('/\b(?:authorization|bearer|api[_-]?key|token|password|secret)\s*[:=]/i', $reason)) {
            throw new InvalidArgumentException('Payment review notes must not contain credentials.');
        }
        $fingerprint = hash('sha256', json_encode([$admin->id, $orderUuid, $receiptId, $kind, $reason], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($admin, $orderUuid, $receiptId, $kind, $reason, $requestUuid, $fingerprint): int {
            $order = DonationOrder::query()->where('order_uuid', $orderUuid)->lockForUpdate()->firstOrFail();
            $existing = DB::table('module_donation_payment_review_events')->where('request_uuid', $requestUuid)->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->fingerprint, $fingerprint)) {
                    throw new LogicException('Conflicting payment review request.');
                }

                return (int) $existing->id;
            }
            $receipt = DB::table('module_donation_payment_receipts')->where('id', $receiptId)
                ->where('order_id', $order->id)->lockForUpdate()->first();
            if ($receipt === null || $receipt->status === 'test') {
                throw new InvalidArgumentException('Payment review must reference a real receipt for the same order.');
            }
            $preparation = DB::table('module_donation_payment_preparations')->where('id', $receipt->preparation_id)->first();
            if ($preparation === null || $preparation->mode !== 'live'
                || (int) $preparation->order_id !== (int) $order->id
                || $preparation->provider_code !== $receipt->provider_code) {
                throw new InvalidArgumentException('Test preparations cannot be reported as live payment evidence.');
            }
            if ($kind === 'review_resolved') {
                $latestReport = DB::table('module_donation_payment_review_events')->where('receipt_id', $receiptId)
                    ->whereIn('kind', ['dispute_reported', 'refund_reported', 'chargeback_reported', 'review_resolved'])
                    ->orderByDesc('id')->first();
                if ($latestReport === null || $latestReport->kind === 'review_resolved') {
                    throw new LogicException('No unresolved reported dispute to resolve.');
                }
            }
            if ($kind === 'refund_reported' || $kind === 'chargeback_reported') {
                if ($receipt->status !== 'credited') {
                    throw new LogicException('A refund or chargeback report requires a credited payment receipt.');
                }
            }
            $id = DB::table('module_donation_payment_review_events')->insertGetId([
                'order_id' => $order->id, 'receipt_id' => $receiptId,
                'admin_id' => $admin->getKey(), 'request_uuid' => $requestUuid,
                'fingerprint' => $fingerprint, 'kind' => $kind, 'source' => 'manual',
                'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($this->audit->success(category: 'module', action: 'donations.payment_review_reported', actor: $admin,
                target: 'donation-order', details: [
                    'order_uuid' => $order->order_uuid, 'receipt_id' => $receiptId,
                    'event_id' => $id, 'kind' => $kind, 'source' => 'manual',
                ]) === null) {
                throw new RuntimeException('Unable to audit a payment review report.');
            }

            return (int) $id;
        }, 3);
    }
}
