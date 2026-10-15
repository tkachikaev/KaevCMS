<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminRole;
use App\Contracts\WalletGateway;
use App\Exceptions\WalletOperationException;
use App\Models\Admin;
use App\Models\User;
use App\Models\WalletStatusEvent;
use App\Services\AuditLogger;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class WalletStatusManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_editors_and_auditors_cannot_change_restrictions(): void
    {
        $user = User::factory()->create();
        $url = route('admin.wallets.status', $user);
        $this->post($url, $this->form())->assertRedirect(route('admin.login'));
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Editor]), 'admin')
            ->post($url, $this->form())->assertForbidden();
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Auditor]), 'admin')
            ->get(route('admin.wallets.show', $user))->assertOk()->assertDontSee('admin-wallet-status-form');
        $this->post($url, $this->form())->assertForbidden();
        $this->assertDatabaseCount('wallet_status_events', 0);
        $this->assertDatabaseCount('wallets', 0);
    }

    public function test_owner_blocks_admin_unblocks_and_history_survives_audit_cleanup(): void
    {
        $user = User::factory()->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $admin = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $this->actingAs($owner, 'admin');
        $this->get(route('admin.wallets.show', $user))
            ->assertOk()->assertSee('admin-wallet-status-form');
        $this->post(route('admin.wallets.status', $user), $this->form())
            ->assertRedirect(route('admin.wallets.show', $user));
        $this->assertTrue($user->fresh()->wallet->is_blocked);

        // Freeze spending, but do not prevent incoming money/coins from being reconciled.
        $engine = app(WalletTransactionService::class);
        $engine->credit($user, 50, 'donations', 'paid-1');
        try {
            $engine->debit($user, 10, 'shop', 'order-1');
            $this->fail('Blocked wallets must not allow debits.');
        } catch (WalletOperationException $exception) {
            $this->assertSame('wallet_blocked', $exception->reason);
        }
        $this->actingAs($admin, 'admin');
        $this->post(route('admin.wallets.status', $user), $this->form([
            'action' => 'unblock', 'reason' => 'Verified ownership and dispute resolved',
        ]))->assertRedirect(route('admin.wallets.show', $user));
        $this->assertFalse($user->fresh()->wallet->is_blocked);
        $this->assertSame(50, $user->fresh()->wallet->balance);
        $this->assertDatabaseCount('wallet_status_events', 2);
        $this->assertDatabaseHas('wallet_status_events', ['wallet_id' => $user->wallet->id,
            'actor_admin_id' => $owner->id, 'was_blocked' => false, 'is_blocked' => true]);
        $this->assertDatabaseHas('wallet_status_events', ['actor_admin_id' => $admin->id,
            'was_blocked' => true, 'is_blocked' => false]);
        DB::table('audit_logs')->delete();
        $this->assertDatabaseCount('wallet_status_events', 2);
        $this->get(route('admin.wallets.show', $user))->assertOk()
            ->assertSee('Verified ownership and dispute resolved')->assertSee('admin-wallet-status-event');
    }

    public function test_idempotent_replay_has_no_new_audit_or_events_and_different_content_is_rejected(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->actingAs($admin, 'admin');
        $form = $this->form();
        $url = route('admin.wallets.status', $user);
        $this->post($url, $form)->assertRedirect();
        $this->post($url, $form)->assertRedirect();
        $this->assertDatabaseCount('wallet_status_events', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->post($url, array_merge($form, ['reason' => 'Different reason']))
            ->assertSessionHasErrors('status_change');
        $other = User::factory()->create();
        $this->post(route('admin.wallets.status', $other), $form)->assertSessionHasErrors('status_change');
        $this->assertDatabaseCount('wallet_status_events', 1);
        $this->assertFalse($other->fresh()->wallet?->is_blocked ?? false);
    }

    public function test_noop_is_not_logged_and_bad_input_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $url = route('admin.wallets.status', $user);
        foreach (['', 'x', str_repeat('x', 191)] as $reason) {
            $this->post($url, $this->form(['reason' => $reason]))->assertSessionHasErrors('reason');
        }
        $this->post($url, $this->form(['action' => 'delete']))->assertSessionHasErrors('action');
        $this->post($url, $this->form(['confirm_status_change' => '0']))->assertSessionHasErrors('confirm_status_change');
        $this->post($url, $this->form(['operation_key' => 'not-a-uuid']))->assertSessionHasErrors('operation_key');
        $this->post($url, $this->form(['action' => 'unblock']))->assertSessionHasErrors('status_change');
        $this->assertDatabaseCount('wallet_status_events', 0);
    }

    public function test_unblock_fails_on_corrupt_balance_but_freeze_still_works(): void
    {
        $user = User::factory()->create();
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        app(WalletTransactionService::class)->credit($user, 10, 'donations', 'topup-1');
        DB::table('wallets')->where('user_id', $user->id)->update(['balance' => 99]);
        $url = route('admin.wallets.status', $user);
        $this->post($url, $this->form())->assertRedirect();
        $this->assertTrue($user->fresh()->wallet->is_blocked);
        $this->post($url, $this->form(['action' => 'unblock']))->assertSessionHasErrors('status_change');
        $this->assertTrue($user->fresh()->wallet->is_blocked);
        $this->assertDatabaseCount('wallet_status_events', 1);
        $this->assertSame(99, $user->fresh()->wallet->balance);
    }

    public function test_failed_audit_rolls_back_new_wallet_status_and_event(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->actingAs($admin, 'admin');
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('success')->once()->andReturnNull();
        app()->instance(AuditLogger::class, $logger);
        $this->post(route('admin.wallets.status', $user), $this->form())
            ->assertSessionHasErrors('status_change');
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('wallet_status_events', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_gateway_refuses_impersonation_of_an_administrator(): void
    {
        $user = User::factory()->create();
        $real = Admin::factory()->create(['role' => AdminRole::Owner]);
        $other = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $this->actingAs($real, 'admin');
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(WalletGateway::class)->changeBlockStatus($other, $user, true, 'status:'.Str::uuid(), 'Test mismatch');
    }

    public function test_status_history_cannot_be_cascade_deleted_or_rolled_back(): void
    {
        $user = User::factory()->create();
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');
        $this->post(route('admin.wallets.status', $user), $this->form())->assertRedirect();
        $this->assertSame(['*'], (new WalletStatusEvent)->getGuarded());
        $migration = require database_path('migrations/2026_10_15_000001_create_wallet_status_events_table.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('status history exists');
        $migration->down();
    }

    /** @param array<string,string> $override
     *  @return array<string,string>
     */
    private function form(array $override = []): array
    {
        return array_merge([
            'action' => 'block',
            'reason' => 'Suspicious payment dispute',
            'operation_key' => (string) Str::uuid(),
            'confirm_status_change' => '1',
        ], $override);
    }
}
