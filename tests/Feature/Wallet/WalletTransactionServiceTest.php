<?php

namespace Tests\Feature\Wallet;

use App\Exceptions\WalletOperationException;
use App\Models\Admin;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletEntry;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class WalletTransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): WalletTransactionService
    {
        return app(WalletTransactionService::class);
    }

    public function test_new_user_wallet_is_created_on_first_credit_with_an_atomic_ledger_record(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->wallet);

        $entry = $this->engine()->credit($user, 100, 'manual', 'gift-001', 'partner-1', 'Partner reward');

        $wallet = $user->fresh()->wallet;
        $this->assertInstanceOf(Wallet::class, $wallet);
        $this->assertSame(100, $wallet->balance);
        $this->assertSame($wallet->id, $entry->wallet_id);
        $this->assertSame('credit', $entry->direction);
        $this->assertSame(100, $entry->amount);
        $this->assertSame(0, $entry->balance_before);
        $this->assertSame(100, $entry->balance_after);
        $this->assertSame(64, strlen($entry->request_fingerprint));
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $entry->operation_uuid);
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_debit_is_serialized_and_records_correct_before_and_after_balances(): void
    {
        $user = User::factory()->create();
        $engine = $this->engine();
        $engine->credit($user, 100, 'manual', 'gift');
        $debit = $engine->debit($user, 40, 'shop', 'order-42');

        $this->assertSame(100, $debit->balance_before);
        $this->assertSame(60, $debit->balance_after);
        $this->assertSame(60, $user->fresh()->wallet->balance);
        $this->assertSame(['credit', 'debit'], WalletEntry::query()->orderBy('id')->pluck('direction')->all());
    }

    public function test_exact_replay_returns_the_original_entry_without_adding_coins_again(): void
    {
        $user = User::factory()->create();
        $engine = $this->engine();
        $first = $engine->credit($user, 500, 'donations', 'payment-001', 'invoice', 'Paid');
        $again = $engine->credit($user, 500, 'donations', 'payment-001', 'invoice', 'Paid');

        $this->assertSame($first->id, $again->id);
        $this->assertSame($first->operation_uuid, $again->operation_uuid);
        $this->assertSame(500, $user->fresh()->wallet->balance);
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_changed_amount_direction_or_reason_with_same_key_is_rejected(): void
    {
        $user = User::factory()->create();
        $engine = $this->engine();
        $engine->credit($user, 100, 'manual', 'same-key', 'gift', 'First');

        foreach ([
            fn () => $engine->credit($user, 101, 'manual', 'same-key', 'gift', 'First'),
            fn () => $engine->debit($user, 100, 'manual', 'same-key', 'gift', 'First'),
            fn () => $engine->credit($user, 100, 'manual', 'same-key', 'gift', 'Changed'),
        ] as $request) {
            try {
                $request();
                $this->fail('Reusing a key for a changed operation must be rejected.');
            } catch (WalletOperationException $exception) {
                $this->assertSame('idempotency_conflict', $exception->reason);
            }
        }
        $this->assertSame(100, $user->fresh()->wallet->balance);
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_source_scoped_key_cannot_be_reused_for_another_user(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $engine = $this->engine();
        $engine->credit($first, 10, 'donations', 'payment-001');

        try {
            $engine->credit($second, 10, 'donations', 'payment-001');
            $this->fail('A payment cannot credit a different account.');
        } catch (WalletOperationException $exception) {
            $this->assertSame('idempotency_conflict', $exception->reason);
        }

        $otherSource = $engine->credit($second, 10, 'manual', 'payment-001');
        $this->assertSame(10, $otherSource->balance_after);
        $this->assertDatabaseCount('wallet_entries', 2);
    }

    public function test_insufficient_funds_and_zero_negative_amounts_do_not_write(): void
    {
        $user = User::factory()->create();
        $engine = $this->engine();
        $engine->credit($user, 3, 'manual', 'starter');
        foreach ([0, -1, 1.5, '5', true, null] as $amount) {
            try {
                $engine->credit($user, $amount, 'manual', 'invalid');
                $this->fail('Nonpositive amounts cannot be used.');
            } catch (InvalidArgumentException) {
                // Expected validation result.
            }
        }
        try {
            $engine->debit($user, 4, 'shop', 'order-1');
            $this->fail('The wallet must not go negative.');
        } catch (WalletOperationException $exception) {
            $this->assertSame('insufficient_funds', $exception->reason);
        }
        $this->assertSame(3, $user->fresh()->wallet->balance);
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_blocked_wallet_refuses_debits_but_allows_credits_and_exact_replays(): void
    {
        $user = User::factory()->create();
        $engine = $this->engine();
        $initial = $engine->credit($user, 20, 'manual', 'starter');
        DB::table('wallets')->where('user_id', $user->id)->update(['is_blocked' => true]);

        try {
            $engine->debit($user, 1, 'shop', 'order-1');
            $this->fail('A blocked wallet cannot spend.');
        } catch (WalletOperationException $exception) {
            $this->assertSame('wallet_blocked', $exception->reason);
        }
        $this->assertSame($initial->id, $engine->credit($user, 20, 'manual', 'starter')->id);
        $credit = $engine->credit($user, 30, 'donations', 'paid-1');
        $this->assertSame(50, $credit->balance_after);
        $this->assertSame(50, $user->fresh()->wallet->balance);
        $this->assertDatabaseCount('wallet_entries', 2);
    }

    public function test_integer_overflow_is_rejected_before_the_ledger_changes(): void
    {
        $user = User::factory()->create();
        $engine = $this->engine();
        $engine->credit($user, PHP_INT_MAX, 'manual', 'maximum');
        try {
            $engine->credit($user, 1, 'manual', 'overflow');
            $this->fail('Balance overflow must be detected.');
        } catch (WalletOperationException $exception) {
            $this->assertSame('balance_overflow', $exception->reason);
        }
        $this->assertSame(PHP_INT_MAX, $user->fresh()->wallet->balance);
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_balance_above_php_integer_limit_is_rejected_without_truncation(): void
    {
        $user = User::factory()->create();
        $engine = $this->engine();
        $engine->credit($user, 1, 'manual', 'seed');
        // MySQL UNSIGNED BIGINT can contain this value; SQLite cannot.
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->assertTrue(true);

            return;
        }
        DB::table('wallets')->where('user_id', $user->id)->update(['balance' => '9223372036854775808']);
        try {
            $engine->debit($user, 1, 'shop', 'next');
            $this->fail('Out-of-range database balances must not be truncated.');
        } catch (WalletOperationException $exception) {
            $this->assertSame('balance_out_of_range', $exception->reason);
        }
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_manual_balance_tampering_is_detected_and_not_automatically_repaired(): void
    {
        $user = User::factory()->create();
        $engine = $this->engine();
        $engine->credit($user, 100, 'manual', 'original');
        DB::table('wallets')->where('user_id', $user->id)->update(['balance' => 999]);
        try {
            $engine->debit($user, 20, 'shop', 'next');
            $this->fail('Ledger drift must not be silently accepted.');
        } catch (WalletOperationException $exception) {
            $this->assertSame('ledger_mismatch', $exception->reason);
        }
        $this->assertSame(999, $user->fresh()->wallet->balance);
        $this->assertDatabaseCount('wallet_entries', 1);
    }

    public function test_persisted_actor_is_included_in_fingerprint_and_recorded(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create();
        $engine = $this->engine();
        $entry = $engine->credit($user, 100, 'manual', 'admin-gift', null, 'Partner', $admin);
        $this->assertSame($admin->id, $entry->actor_admin_id);
        $this->assertNull($entry->actor_user_id);

        try {
            $engine->credit($user, 100, 'manual', 'admin-gift', null, 'Partner');
            $this->fail('The same key with a different actor must be rejected.');
        } catch (WalletOperationException $exception) {
            $this->assertSame('idempotency_conflict', $exception->reason);
        }
    }

    public function test_invalid_operation_names_or_unbounded_metadata_are_rejected(): void
    {
        $user = User::factory()->create();
        foreach ([
            fn () => $this->engine()->credit($user, 1, '../', 'key'),
            fn () => $this->engine()->credit($user, 1, 'manual', ''),
            fn () => $this->engine()->credit($user, 1, 'manual', str_repeat('x', 121)),
            fn () => $this->engine()->credit($user, 1, 'manual', 'key', null, str_repeat('x', 4097)),
        ] as $request) {
            try {
                $request();
                $this->fail('Invalid inputs must fail validation.');
            } catch (InvalidArgumentException) {
                // Expected validation result.
            }
        }
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_error_after_updating_balance_rolls_back_both_balance_and_ledger(): void
    {
        $user = User::factory()->create();
        $failed = false;
        DB::listen(static function ($event) use (&$failed): void {
            $sql = strtolower($event->sql);
            if (! $failed && str_contains($sql, 'insert') && str_contains($sql, 'wallet_entries')) {
                $failed = true;
                throw new RuntimeException('Simulated failure after ledger INSERT.');
            }
        });

        try {
            $this->engine()->credit($user, 100, 'manual', 'failed-write');
            $this->fail('The injected write failure must abort the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure after ledger INSERT.', $exception->getMessage());
        }
        $this->assertTrue($failed);
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('wallet_entries', 0);
    }
}
