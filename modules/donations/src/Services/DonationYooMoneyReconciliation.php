<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;
use RuntimeException;
use Throwable;

/**
 * Out-of-band recovery for missing webhooks. The history endpoint does not
 * attest to the original gross payer debit; NEVER turn these candidates into
 * Wallet credits. It only alerts administrators for manual investigation.
 */
final class DonationYooMoneyReconciliation
{
    public function __construct(
        private readonly DonationProviderSettings $settings,
        private readonly YooMoneyRecoveryProbe $probe,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{scanned:int,candidates:int,errors:int} */
    public function scan(int $limit = 30, ?string $orderUuid = null): array
    {
        $result = ['scanned' => 0, 'candidates' => 0, 'errors' => 0];
        $query = DonationPaymentPreparation::query()->where('provider_code', 'yoomoney')
            ->where('mode', 'live')->orderBy('id');
        if ($orderUuid !== null) {
            if (! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $orderUuid)) {
                throw new RuntimeException('Invalid order UUID.');
            }
            $query->whereHas('order', static function ($q) use ($orderUuid): void {
                $q->where('order_uuid', $orderUuid);
            });
        }
        $records = $query->limit(max(1, min(100, $limit)))->get();
        $credentials = $this->settings->credentialsForDriver('yoomoney');
        foreach ($records as $preparation) {
            $result['scanned']++;
            $order = DB::table('module_donation_orders')->where('id', $preparation->order_id)->first();
            if ($order === null || $order->status === 'credited') {
                continue;
            }
            try {
                foreach ($this->probe->candidates($order->order_uuid, $credentials) as $candidate) {
                    // Report only real successfully completed incoming transfers.
                    if ($candidate['status'] !== 'success' || DB::table('module_donation_payment_receipts')
                        ->where('provider_code', 'yoomoney')->where('provider_payment_id', $candidate['operation_id'])->exists()) {
                        continue;
                    }
                    DB::transaction(function () use ($preparation, $candidate, $order, &$result): void {
                        $existing = DB::table('module_donation_recovery_candidates')
                            ->where('preparation_id', $preparation->id)
                            ->where('provider_operation_id', $candidate['operation_id'])->lockForUpdate()->first();
                        if ($existing !== null) {
                            return;
                        }
                        DB::table('module_donation_recovery_candidates')->insert([
                            'preparation_id' => $preparation->id,
                            'provider_operation_id' => $candidate['operation_id'],
                            'provider_status' => 'success', 'created_at' => now(), 'updated_at' => now(),
                        ]);
                        if ($this->audit->system('module', 'donations.recovery_candidate', 'donation-order', [
                            'order_uuid' => $order->order_uuid, 'provider_code' => 'yoomoney',
                            'operation_id' => $candidate['operation_id'],
                        ]) === null) {
                            throw new RuntimeException('Recovery evidence audit failed.');
                        }
                        $result['candidates']++;
                    }, 3);
                }
            } catch (Throwable) {
                $result['errors']++; // No OAuth token / provider responses in CLI output.
            }
        }

        return $result;
    }
}
