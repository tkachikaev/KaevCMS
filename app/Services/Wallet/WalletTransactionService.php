<?php

namespace App\Services\Wallet;

use App\Exceptions\WalletOperationException;
use App\Models\Admin;
use App\Models\User;
use App\Models\WalletEntry;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Internal atomic wallet engine. Only CoreWalletGateway is the public module
 * contract; never call this engine directly from an HTTP/Livewire action.
 *
 * All reads/writes use the primary CMS connection, never a game database.
 */
final class WalletTransactionService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function credit(
        User $user,
        mixed $amount,
        string $source,
        string $sourceKey,
        ?string $reference = null,
        ?string $reason = null,
        User|Admin|null $actor = null,
    ): WalletEntry {
        return $this->apply($user, 'credit', $amount, $source, $sourceKey, $reference, $reason, $actor);
    }

    public function debit(
        User $user,
        mixed $amount,
        string $source,
        string $sourceKey,
        ?string $reference = null,
        ?string $reason = null,
        User|Admin|null $actor = null,
    ): WalletEntry {
        return $this->apply($user, 'debit', $amount, $source, $sourceKey, $reference, $reason, $actor);
    }

    private function apply(
        User $user,
        string $direction,
        mixed $amount,
        string $source,
        string $sourceKey,
        ?string $reference,
        ?string $reason,
        User|Admin|null $actor,
    ): WalletEntry {
        if (! $user->exists || (int) $user->getKey() <= 0) {
            throw new InvalidArgumentException('A persisted CMS user is required.');
        }
        // `int` type declarations alone coerce floats/numeric strings in
        // weakly-typed callers. Financial quantities must never be coerced.
        if (! is_int($amount) || $amount <= 0) {
            throw new InvalidArgumentException('Wallet amount must be a positive integer.');
        }
        $source = trim($source);
        $sourceKey = trim($sourceKey);
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $source) !== 1) {
            throw new InvalidArgumentException('Invalid wallet operation source.');
        }
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,119}\z/D', $sourceKey) !== 1) {
            throw new InvalidArgumentException('Invalid wallet operation idempotency key.');
        }
        $reference = $this->optionalText($reference, 190);
        $reason = $this->optionalText($reason, 4096);
        if ($actor !== null && (! $actor->exists || (int) $actor->getKey() <= 0)) {
            throw new InvalidArgumentException('Wallet actor must exist.');
        }

        $userId = (int) $user->getKey();
        $actorUserId = $actor instanceof User ? (int) $actor->getKey() : null;
        $actorAdminId = $actor instanceof Admin ? (int) $actor->getKey() : null;
        $fingerprint = hash('sha256', json_encode([
            'user_id' => $userId,
            'direction' => $direction,
            'amount' => $amount,
            'source' => $source,
            'source_key' => $sourceKey,
            'reference' => $reference,
            'reason' => $reason,
            'actor_user_id' => $actorUserId,
            'actor_admin_id' => $actorAdminId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        try {
            return DB::transaction(function () use (
                $userId, $direction, $amount, $source, $sourceKey, $fingerprint,
                $reference, $reason, $actorUserId, $actorAdminId,
            ): WalletEntry {
                // Serialize even the first wallet creation for newly registered users.
                User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

                // Lock the balance row before checking or changing it.
                $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
                if ($wallet === null) {
                    $walletId = DB::table('wallets')->insertGetId([
                        'user_id' => $userId,
                        'balance' => 0,
                        'is_blocked' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $wallet = DB::table('wallets')->where('id', $walletId)->lockForUpdate()->first();
                }
                if ($wallet === null) {
                    throw new WalletOperationException('wallet_unavailable');
                }

                // Unique constraint handles cross-user races; avoid gap locks on missing keys.
                $existing = DB::table('wallet_entries')
                    ->where('source', $source)->where('source_key', $sourceKey)->first();
                if ($existing !== null) {
                    return $this->sameOperation($existing, $userId, $fingerprint);
                }

                // MySQL UNSIGNED BIGINT allows values beyond PHP_INT_MAX; never
                // truncate those values through a PHP integer cast.
                $balanceRaw = (string) $wallet->balance;
                $maximum = (string) PHP_INT_MAX;
                if (preg_match('/\A(?:0|[1-9][0-9]*)\z/', $balanceRaw) !== 1
                    || strlen($balanceRaw) > strlen($maximum)
                    || (strlen($balanceRaw) === strlen($maximum) && strcmp($balanceRaw, $maximum) > 0)) {
                    throw new WalletOperationException('balance_out_of_range');
                }
                $balance = (int) $balanceRaw;
                $last = DB::table('wallet_entries')->where('wallet_id', $wallet->id)
                    ->orderByDesc('id')->lockForUpdate()->first(['balance_after']);
                if (($last === null && $balance !== 0)
                    || ($last !== null && (string) $last->balance_after !== $balanceRaw)) {
                    // Never overwrite a balance that no longer matches its journal.
                    throw new WalletOperationException('ledger_mismatch');
                }

                if ($direction === 'debit') {
                    if ((bool) $wallet->is_blocked) {
                        throw new WalletOperationException('wallet_blocked');
                    }
                    if ($amount > $balance) {
                        throw new WalletOperationException('insufficient_funds');
                    }
                    $nextBalance = $balance - $amount;
                } else {
                    if ($amount > PHP_INT_MAX - $balance) {
                        throw new WalletOperationException('balance_overflow');
                    }
                    $nextBalance = $balance + $amount;
                }

                $now = now();
                DB::table('wallets')->where('id', $wallet->id)->update([
                    'balance' => $nextBalance,
                    'updated_at' => $now,
                ]);
                $id = DB::table('wallet_entries')->insertGetId([
                    'wallet_id' => $wallet->id,
                    'operation_uuid' => (string) Str::uuid(),
                    'source' => $source,
                    'source_key' => $sourceKey,
                    'request_fingerprint' => $fingerprint,
                    'direction' => $direction,
                    'amount' => $amount,
                    'balance_before' => $balance,
                    'balance_after' => $nextBalance,
                    'reference' => $reference,
                    'reason' => $reason,
                    'actor_user_id' => $actorUserId,
                    'actor_admin_id' => $actorAdminId,
                    'occurred_at' => $now,
                ]);

                $entry = WalletEntry::query()->findOrFail($id);
                // A financial mutation without an audit row is a failed mutation.
                // The existing CMS audit logger is normally best-effort; wallets
                // deliberately turn that into a fail-closed transactional requirement.
                $audit = $this->auditLogger->success(
                    'wallet',
                    'transaction.'.$direction,
                    $actorUserId !== null ? User::query()->findOrFail($actorUserId)
                        : ($actorAdminId !== null ? Admin::query()->findOrFail($actorAdminId) : 'wallet:'.$source),
                    User::query()->findOrFail($userId),
                    [
                        'operation_uuid' => (string) $entry->operation_uuid,
                        'wallet_id' => (int) $wallet->id,
                        'source' => $source,
                        'direction' => $direction,
                        'amount' => $amount,
                        'balance_before' => $balance,
                        'balance_after' => $nextBalance,
                    ],
                );
                if ($audit === null) {
                    throw new WalletOperationException('audit_unavailable');
                }

                return $entry;
            }, 3); // Laravel retries deadlocks, but not arbitrary validation failures.
        } catch (QueryException $exception) {
            // A different user's transaction may have won the same global key.
            // Consult the committed ledger after rollback; never retry a credit blindly.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }
            $existing = DB::table('wallet_entries')
                ->where('source', $source)->where('source_key', $sourceKey)->first();
            if ($existing === null) {
                throw $exception;
            }

            return $this->sameOperation($existing, $userId, $fingerprint);
        }
    }

    private function sameOperation(object $existing, int $userId, string $fingerprint): WalletEntry
    {
        if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
            throw new WalletOperationException('idempotency_conflict');
        }
        // Fingerprint includes the target user, and we also enforce ownership.
        $wallet = DB::table('wallets')->where('id', $existing->wallet_id)->first(['user_id']);
        if ($wallet === null || (int) $wallet->user_id !== $userId) {
            throw new WalletOperationException('idempotency_conflict');
        }

        return WalletEntry::query()->findOrFail($existing->id);
    }

    private function optionalText(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit) {
            throw new InvalidArgumentException('Invalid wallet operation text.');
        }

        return $value;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true)
            || (string) ($exception->errorInfo[0] ?? '') === '19';
    }
}
