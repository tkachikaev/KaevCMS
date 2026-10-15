<?php

namespace Tests\Feature\Wallet;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WalletSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_tables_have_expected_columns_and_new_users_are_not_given_a_fictitious_credit(): void
    {
        $this->assertTrue(Schema::hasTable('wallets'));
        $this->assertTrue(Schema::hasTable('wallet_entries'));
        foreach (['user_id', 'balance', 'is_blocked', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('wallets', $column));
        }
        foreach (['wallet_id', 'operation_uuid', 'source', 'source_key', 'request_fingerprint', 'direction', 'amount', 'balance_before', 'balance_after', 'actor_admin_id', 'actor_user_id', 'occurred_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('wallet_entries', $column));
        }

        $user = User::factory()->create();
        $this->assertNull($user->wallet);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_upgrade_seeds_existing_users_with_exactly_one_zero_wallet_and_preserves_users(): void
    {
        $statusMigration = require database_path('migrations/2026_10_15_000001_create_wallet_status_events_table.php');
        $statusMigration->down(); // Isolate published A1 migration from the later A7 foreign key.
        $migration = require database_path('migrations/2026_10_15_000000_create_wallet_tables.php');
        $migration->down();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $originalUsers = User::query()->count();

        $migration->up();

        $this->assertSame($originalUsers, User::query()->count());
        $this->assertSame(2, Wallet::query()->count());
        foreach ([$first, $second] as $user) {
            $wallet = $user->fresh()->wallet;
            $this->assertNotNull($wallet);
            $this->assertSame(0, $wallet->balance);
            $this->assertFalse($wallet->is_blocked);
        }
        $this->assertDatabaseCount('wallet_entries', 0);
        $statusMigration->up(); // Restore A7 schema for the remaining test scope.
    }

    public function test_one_user_cannot_own_two_wallets(): void
    {
        $user = User::factory()->create();
        DB::table('wallets')->insert(['user_id' => $user->id, 'balance' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(QueryException::class);
        DB::table('wallets')->insert(['user_id' => $user->id, 'balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_operation_uuid_is_globally_unique(): void
    {
        $wallet = $this->walletFor(User::factory()->create());
        $this->insertEntry($wallet, 'source-a', 'key-1', '00000000-0000-4000-8000-000000000001');

        $this->expectException(QueryException::class);
        $this->insertEntry($wallet, 'source-b', 'key-2', '00000000-0000-4000-8000-000000000001');
    }

    public function test_source_and_idempotency_key_must_be_unique_as_a_pair(): void
    {
        $wallet = $this->walletFor(User::factory()->create());
        $this->insertEntry($wallet, 'donations', 'paid-1', '00000000-0000-4000-8000-000000000001');
        $this->insertEntry($wallet, 'manual', 'paid-1', '00000000-0000-4000-8000-000000000002');

        $this->expectException(QueryException::class);
        $this->insertEntry($wallet, 'donations', 'paid-1', '00000000-0000-4000-8000-000000000003');
    }

    public function test_user_with_a_wallet_cannot_be_cascade_deleted(): void
    {
        $user = User::factory()->create();
        $this->walletFor($user);

        $this->expectException(QueryException::class);
        $user->delete();
    }

    public function test_wallet_with_ledger_entries_cannot_be_deleted(): void
    {
        $wallet = $this->walletFor(User::factory()->create());
        $this->insertEntry($wallet, 'donations', 'paid-1', '00000000-0000-4000-8000-000000000001');

        $this->expectException(QueryException::class);
        $wallet->delete();
    }

    public function test_funded_wallet_cannot_be_removed_by_rolling_back_the_migration(): void
    {
        $wallet = $this->walletFor(User::factory()->create());
        DB::table('wallets')->where('id', $wallet->id)->update(['balance' => 10]);
        $migration = require database_path('migrations/2026_10_15_000000_create_wallet_tables.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nonzero balances');
        $migration->down();
    }

    public function test_ledger_prevents_rollback_even_when_wallet_balance_is_zero(): void
    {
        $wallet = $this->walletFor(User::factory()->create());
        $this->insertEntry($wallet, 'donations', 'paid-1', '00000000-0000-4000-8000-000000000001');
        $migration = require database_path('migrations/2026_10_15_000000_create_wallet_tables.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('transaction history exists');
        $migration->down();
    }

    public function test_wallet_and_ledger_models_forbid_generic_mass_assignment(): void
    {
        $this->assertSame(['*'], (new Wallet)->getGuarded());
        $this->assertSame(['*'], (new \App\Models\WalletEntry)->getGuarded());
    }

    private function walletFor(User $user): Wallet
    {
        $id = DB::table('wallets')->insertGetId([
            'user_id' => $user->id,
            'balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Wallet::query()->findOrFail($id);
    }

    private function insertEntry(Wallet $wallet, string $source, string $key, string $uuid): void
    {
        DB::table('wallet_entries')->insert([
            'wallet_id' => $wallet->id,
            'operation_uuid' => $uuid,
            'source' => $source,
            'source_key' => $key,
            'request_fingerprint' => str_repeat('a', 64),
            'direction' => 'credit',
            'amount' => 1,
            'balance_before' => 0,
            'balance_after' => 1,
            'occurred_at' => now(),
        ]);
    }
}
