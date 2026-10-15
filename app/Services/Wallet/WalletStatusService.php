<?php

namespace App\Services\Wallet;

use App\Exceptions\WalletOperationException;
use App\Models\Admin;
use App\Models\User;
use App\Models\WalletStatusEvent;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Internal CMS-only restriction writer. Authorization belongs to WalletGateway. */
final class WalletStatusService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly WalletLedgerReconciler $reconciler,
    ) {}

    public function change(Admin $actor, User $user, bool $blocked, string $key, string $reason): WalletStatusEvent
    {
        if (! $actor->exists || ! $user->exists) {
            throw new InvalidArgumentException('An existing administrator and CMS user are required.');
        }
        $key = trim($key);
        $reason = trim($reason);
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,119}\z/D', $key) !== 1) {
            throw new InvalidArgumentException('Invalid wallet status operation key.');
        }
        if (! mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) < 3 || mb_strlen($reason) > 190) {
            throw new InvalidArgumentException('A wallet status reason of 3 to 190 characters is required.');
        }
        $actorId = (int) $actor->getKey();
        $userId = (int) $user->getKey();
        $fingerprint = hash('sha256', json_encode([
            'admin' => $actorId,
            'user' => $userId,
            'blocked' => $blocked,
            'key' => $key,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        try {
            return DB::transaction(function () use ($actorId, $userId, $blocked, $key, $reason, $fingerprint): WalletStatusEvent {
                // Lock the CMS user first, in the same order as WalletTransactionService.
                $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
                $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
                if ($wallet === null) {
                    $walletId = DB::table('wallets')->insertGetId([
                        'user_id' => $userId, 'balance' => 0, 'is_blocked' => false,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $wallet = DB::table('wallets')->where('id', $walletId)->lockForUpdate()->first();
                }
                if ($wallet === null) {
                    throw new WalletOperationException('wallet_unavailable');
                }

                $existing = DB::table('wallet_status_events')->where('operation_key', $key)->first();
                if ($existing !== null) {
                    return $this->sameOperation($existing, $fingerprint, (int) $wallet->id);
                }
                $wasBlocked = (bool) $wallet->is_blocked;
                if ($wasBlocked === $blocked) {
                    throw new WalletOperationException('already_in_state');
                }
                // Never re-enable spending if there is already a known ledger mismatch.
                // Blocking is always allowed: freezing a corrupted wallet is safer than leaving it open.
                if (! $blocked && ! $this->reconciler->check((int) $wallet->id)->valid) {
                    throw new WalletOperationException('ledger_mismatch');
                }

                $now = now();
                DB::table('wallets')->where('id', $wallet->id)->update([
                    'is_blocked' => $blocked, 'updated_at' => $now,
                ]);
                $eventId = DB::table('wallet_status_events')->insertGetId([
                    'wallet_id' => $wallet->id, 'actor_admin_id' => $actorId,
                    'operation_key' => $key, 'request_fingerprint' => $fingerprint,
                    'was_blocked' => $wasBlocked, 'is_blocked' => $blocked,
                    'reason' => $reason, 'occurred_at' => $now,
                ]);
                $audit = $this->auditLogger->success(
                    'wallet', $blocked ? 'status.blocked' : 'status.unblocked',
                    Admin::query()->findOrFail($actorId), $user,
                    ['wallet_id' => (int) $wallet->id, 'status_event_id' => $eventId,
                        'was_blocked' => $wasBlocked, 'is_blocked' => $blocked],
                );
                if ($audit === null) {
                    throw new WalletOperationException('audit_unavailable');
                }

                return WalletStatusEvent::query()->findOrFail($eventId);
            }, 3);
        } catch (QueryException $exception) {
            // A unique-key race can span separate wallets; a retry cannot mutate anything twice.
            if (! in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505', '19'], true)) {
                throw $exception;
            }
            $existing = DB::table('wallet_status_events')->where('operation_key', $key)->first();
            if ($existing === null) {
                throw $exception;
            }
            $wallet = DB::table('wallets')->where('id', $existing->wallet_id)->first(['user_id']);
            if ($wallet === null || (int) $wallet->user_id !== $userId) {
                throw new WalletOperationException('idempotency_conflict');
            }

            return $this->sameOperation($existing, $fingerprint, (int) $existing->wallet_id);
        }
    }

    private function sameOperation(object $event, string $fingerprint, int $walletId): WalletStatusEvent
    {
        if ((int) $event->wallet_id !== $walletId
            || ! hash_equals((string) $event->request_fingerprint, $fingerprint)) {
            throw new WalletOperationException('idempotency_conflict');
        }

        return WalletStatusEvent::query()->findOrFail($event->id);
    }
}
