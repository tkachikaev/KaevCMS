<?php

namespace KaevCMS\Modules\Donations\Services;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Providers\YooMoneyProvider;
use RuntimeException;

/**
 * Read-only recovery aid for lost notifications. Returns candidate transaction IDs,
 * NEVER classifies a payment as settled, updates an order, or calls Wallet.
 * Owner supplies a read-only token with operation-history scope; full C3
 * reconciliation must independently verify the detailed amounts and identity.
 */
final class YooMoneyRecoveryProbe
{
    /**
     * @param array<string,string> $credentials
     * @return list<array{operation_id:string,status:string}>
     */
    public function candidates(string $orderUuid, array $credentials): array
    {
        $token = $credentials['history_token'] ?? '';
        if (! is_string($token) || ! preg_match('/\A[\x21-\x7e]{20,1024}\z/D', $token)
            || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $orderUuid)) {
            throw new InvalidArgumentException('Read-only YooMoney recovery is not configured.');
        }
        $label = YooMoneyProvider::label($orderUuid);
        $result = [];
        $next = null;
        $seen = [];
        for ($page = 0; $page < 3; $page++) {
            $form = ['label' => $label, 'records' => '100'];
            if ($next !== null) {
                $form['start_record'] = $next;
            }
            try {
                $response = Http::asForm()->withToken($token)->timeout(8)->post(YooMoneyProvider::HISTORY_URL, $form);
                if (! $response->successful()) {
                    throw new RuntimeException;
                }
                $data = $response->json();
            } catch (\Throwable) {
                // Do not leak OAuth token, URL contents, merchant data or provider response.
                throw new RuntimeException('Unable to query YooMoney operation history.');
            }
            if (! is_array($data) || isset($data['error']) || ! is_array($data['operations'] ?? null)) {
                throw new RuntimeException('Invalid YooMoney history response.');
            }
            foreach ($data['operations'] as $item) {
                if (! is_array($item) || ($item['label'] ?? null) !== $label
                    || ($item['direction'] ?? null) !== 'in'
                    || ! in_array($item['status'] ?? null, ['success', 'refused', 'in_progress'], true)
                    || ! is_string($item['operation_id'] ?? null)
                    || ! preg_match('/\A[0-9]{1,64}\z/D', $item['operation_id'])) {
                    continue;
                }
                if (! isset($seen[$item['operation_id']])) {
                    $result[] = ['operation_id' => $item['operation_id'], 'status' => $item['status']];
                    $seen[$item['operation_id']] = true;
                }
            }
            $next = $data['next_record'] ?? null;
            if ($next === null) {
                return $result;
            }
            if (! is_string($next) || ! preg_match('/\A[0-9]{1,12}\z/D', $next)) {
                throw new RuntimeException('Invalid YooMoney history cursor.');
            }
        }
        // Incomplete scans must not masquerade as proof that no payment exists.
        throw new RuntimeException('YooMoney operation history scan was incomplete.');
    }
}
