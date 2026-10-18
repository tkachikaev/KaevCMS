<?php

namespace Tests\Feature\Wallet;

use App\Exceptions\WalletOperationException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WalletAuditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_new_financial_mutation_has_a_matching_cms_audit_record_and_replay_does_not_duplicate(): void
    {
        $user = User::factory()->create();
        $engine = app(WalletTransactionService::class);
        $first = $engine->credit($user, 30, 'daily_rewards', 'reward-1');
        $engine->credit($user, 30, 'daily_rewards', 'reward-1');
        $this->assertDatabaseCount('audit_logs', 1);
        $record = AuditLog::query()->firstOrFail();
        $this->assertSame('wallet', $record->category);
        $this->assertSame('transaction.credit', $record->action);
        $this->assertSame($first->operation_uuid, $record->details['operation_uuid']);
        $this->assertSame(30, $record->details['balance_after']);
        $this->assertArrayNotHasKey('source_key', $record->details);
        $this->assertArrayNotHasKey('reference', $record->details);
    }

    public function test_failed_audit_write_rolls_back_balance_and_journal_together(): void
    {
        $user = User::factory()->create();
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('success')->once()->andReturnNull();
        app()->instance(AuditLogger::class, $logger);
        try {
            app(WalletTransactionService::class)->credit($user, 100, 'donations', 'payment-1');
            $this->fail('Wallet must fail closed when the audit writer fails.');
        } catch (WalletOperationException $exception) {
            $this->assertSame('audit_unavailable', $exception->reason);
        }
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
