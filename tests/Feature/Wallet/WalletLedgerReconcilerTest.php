<?php

namespace Tests\Feature\Wallet;

use App\Models\User;
use App\Services\Wallet\WalletLedgerReconciler;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletLedgerReconcilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_wallet_and_complete_credit_debit_chain_pass_full_reconciliation(): void
    {
        $user = User::factory()->create();
        $walletId = DB::table('wallets')->insertGetId([
            'user_id' => $user->id, 'balance' => 0, 'is_blocked' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(WalletLedgerReconciler::class);
        $empty = $service->check($walletId);
        $this->assertTrue($empty->valid);
        $this->assertSame(0, $empty->checkedEntries);

        $engine = app(WalletTransactionService::class);
        $engine->credit($user, 100, 'manual', 'gift');
        $engine->debit($user, 30, 'shop', 'purchase');
        $engine->credit($user, 20, 'daily_rewards', 'bonus');
        $result = $service->check($walletId);
        $this->assertTrue($result->valid);
        $this->assertSame('ok', $result->code);
        $this->assertSame(3, $result->checkedEntries);
        $this->assertSame(90, $user->fresh()->wallet->balance);
    }

    public function test_reconciliation_detects_historical_gap_even_if_final_balance_matches(): void
    {
        $user = User::factory()->create();
        $engine = app(WalletTransactionService::class);
        $entry = $engine->credit($user, 100, 'manual', 'one');
        $engine->credit($user, 30, 'manual', 'two');
        DB::table('wallet_entries')->where('id', $entry->id)->update(['balance_after' => 99]);

        $walletId = $user->fresh()->wallet->id;
        $result = app(WalletLedgerReconciler::class)->check($walletId);
        $this->assertFalse($result->valid);
        $this->assertSame('incorrect_entry_balance', $result->code);
        $this->assertSame(130, $user->fresh()->wallet->balance);
        $this->assertDatabaseCount('wallet_entries', 2);
    }

    public function test_reconciliation_detects_wallet_balance_mismatch_without_fixing_it(): void
    {
        $user = User::factory()->create();
        app(WalletTransactionService::class)->credit($user, 12, 'manual', 'one');
        DB::table('wallets')->where('user_id', $user->id)->update(['balance' => 77]);
        $result = app(WalletLedgerReconciler::class)->check($user->fresh()->wallet->id);
        $this->assertFalse($result->valid);
        $this->assertSame('wallet_balance_mismatch', $result->code);
        $this->assertSame(77, $user->fresh()->wallet->balance);
    }

    public function test_cli_reconciles_read_only_and_reports_inconsistencies_as_failure(): void
    {
        $user = User::factory()->create();
        app(WalletTransactionService::class)->credit($user, 15, 'manual', 'one');
        $this->artisan('kaevcms:wallets-reconcile', ['--user' => (string) $user->id])
            ->assertSuccessful();
        DB::table('wallets')->where('user_id', $user->id)->update(['balance' => 999]);
        $this->artisan('kaevcms:wallets-reconcile', ['--user' => (string) $user->id])
            ->assertFailed();
        $this->assertSame(999, $user->fresh()->wallet->balance);
        $this->artisan('kaevcms:wallets-reconcile', ['--user' => '-1'])->assertExitCode(2);
    }
}
